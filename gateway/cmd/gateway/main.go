package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"html/template"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"

	"github.com/hamidsha/vmcontrol-suite/gateway/internal/audit"
	"github.com/hamidsha/vmcontrol-suite/gateway/internal/auth"
	consoleStore "github.com/hamidsha/vmcontrol-suite/gateway/internal/console"
	"github.com/hamidsha/vmcontrol-suite/gateway/internal/config"
	"github.com/hamidsha/vmcontrol-suite/gateway/internal/vsphere"
)

const consoleCookie = "vmcontrol_console"

type server struct {
	cfg      *config.Config
	auth     *auth.Verifier
	vsphere  *vsphere.Manager
	sessions *consoleStore.Store
	audit    *audit.Logger
	template *template.Template
	locksMu  sync.Mutex
	locks    map[string]*sync.Mutex
}

type actionRequest struct { Action string `json:"action"`; ServiceID int64 `json:"service_id"`; UserID int64 `json:"user_id"` }
type consoleRequest struct { ServiceID int64 `json:"service_id"`; UserID int64 `json:"user_id"`; DisplayName string `json:"display_name"` }

func main() {
	cfg, err := config.Load()
	if err != nil { log.Fatal(err) }
	tmpl, err := template.ParseFiles(filepath.Join(cfg.StaticDirectory, "console.html"))
	if err != nil { log.Fatal(err) }
	s := &server{
		cfg: cfg,
		auth: auth.NewVerifier(cfg.APIKey, cfg.APISecret, cfg.RequestSkew),
		vsphere: vsphere.NewManager(cfg.VCenters, cfg.VCenterTimeout),
		sessions: consoleStore.NewStore(cfg.ConsoleTTL, cfg.ConsoleMaxAge),
		audit: audit.New(cfg.AuditLogPath), template: tmpl, locks: make(map[string]*sync.Mutex),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.health)
	mux.Handle("/api/v1/", s.requireHMAC(http.HandlerFunc(s.api)))
	mux.HandleFunc("GET /console/launch/{token}", s.consoleLaunch)
	mux.HandleFunc("GET /console/view", s.consoleView)
	mux.HandleFunc("GET /console/ws", s.consoleWebSocket)
	mux.HandleFunc("GET /console/status", s.consoleStatus)
	mux.HandleFunc("POST /console/action", s.consoleAction)
	mux.HandleFunc("GET /webmks/{asset}", s.webmksAsset)
	mux.HandleFunc("GET /assets/{asset}", s.localAsset)

	h := securityHeaders(mux)
	httpServer := &http.Server{Addr: cfg.ListenAddress, Handler: h, ReadHeaderTimeout: 10*time.Second, ReadTimeout: 30*time.Second, WriteTimeout: 35*time.Second, IdleTimeout: 60*time.Second}
	log.Printf("vmcontrol gateway listening on %s", cfg.ListenAddress)
	log.Fatal(httpServer.ListenAndServe())
}

func (s *server) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status":"ok", "service":"vmcontrol-gateway"})
}

func (s *server) api(w http.ResponseWriter, r *http.Request) {
	parts := strings.Split(strings.Trim(r.URL.Path, "/"), "/")
	if r.Method == http.MethodGet && len(parts) == 5 && parts[0] == "api" && parts[1] == "v1" && parts[2] == "services" && parts[4] == "console-events" {
		serviceID, err := strconv.ParseInt(parts[3], 10, 64)
		if err != nil || serviceID < 1 { writeError(w, http.StatusBadRequest, "invalid_service", "Invalid service identifier"); return }
		writeJSON(w, http.StatusOK, map[string]any{"events": s.audit.RecentConsoleActions(serviceID, 25)})
		return
	}
	if r.Method == http.MethodGet && len(parts) == 3 && parts[0] == "api" && parts[1] == "v1" && parts[2] == "vcenters" {
		writeJSON(w, http.StatusOK, map[string]any{"vcenters": s.vsphere.VCenterIDs()})
		return
	}
	if r.Method == http.MethodGet && len(parts) == 5 && parts[0] == "api" && parts[1] == "v1" && parts[2] == "vcenters" && parts[4] == "vms" {
		started := time.Now()
		vcID := parts[3]
		if !validID(vcID) {
			writeError(w, http.StatusBadRequest, "invalid_target", "Invalid vCenter identifier")
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), s.cfg.VCenterTimeout)
		defer cancel()
		items, err := s.vsphere.List(ctx, vcID)
		if err != nil {
			s.audit.Write(map[string]any{"event": "inventory_list", "vcenter_id": vcID, "success": false, "error": errorString(err), "duration_ms": time.Since(started).Milliseconds(), "remote_ip": clientIP(r)})
			s.vsphereError(w, err)
			return
		}
		s.audit.Write(map[string]any{"event": "inventory_list", "vcenter_id": vcID, "success": true, "vm_count": len(items), "duration_ms": time.Since(started).Milliseconds(), "remote_ip": clientIP(r)})
		writeJSON(w, http.StatusOK, map[string]any{"vcenter_id": vcID, "vms": items})
		return
	}
	if len(parts) < 7 || parts[0] != "api" || parts[1] != "v1" || parts[2] != "vcenters" || parts[4] != "vms" {
		writeError(w, http.StatusNotFound, "not_found", "Endpoint not found"); return
	}
	vcID, uuid := parts[3], parts[5]
	if !validID(vcID) || !validUUID(uuid) { writeError(w, http.StatusBadRequest, "invalid_target", "Invalid vCenter or VM identifier"); return }
	ctx, cancel := context.WithTimeout(r.Context(), s.cfg.VCenterTimeout)
	defer cancel()

	switch {
	case r.Method == http.MethodGet && len(parts) == 8 && parts[6] == "metrics":
		metrics, err := s.vsphere.Metrics(ctx, vcID, uuid, parts[7])
		if err != nil { s.vsphereError(w, err); return }
		writeJSON(w, http.StatusOK, metrics)
	case r.Method == http.MethodGet && len(parts) == 7 && parts[6] == "status":
		info, err := s.vsphere.Info(ctx, vcID, uuid)
		if err != nil { s.vsphereError(w, err); return }
		writeJSON(w, http.StatusOK, info)
	case r.Method == http.MethodPost && len(parts) == 7 && parts[6] == "actions":
		var req actionRequest
		if err := decodeJSON(r.Body, &req); err != nil { writeError(w, http.StatusBadRequest, "invalid_json", err.Error()); return }
		if !s.cfg.AllowedActions[req.Action] { writeError(w, http.StatusForbidden, "action_disabled", "Action is disabled by gateway policy"); return }
		lock := s.vmLock(vcID+":"+uuid); lock.Lock(); defer lock.Unlock()
		err := s.vsphere.Action(ctx, vcID, uuid, req.Action)
		s.audit.Write(map[string]any{"event":"vm_action", "vcenter_id":vcID, "instance_uuid":uuid, "service_id":req.ServiceID, "user_id":req.UserID, "action":req.Action, "success":err==nil, "error":errorString(err), "remote_ip":clientIP(r)})
		if err != nil { s.vsphereError(w, err); return }
		writeJSON(w, http.StatusAccepted, map[string]any{"status":"accepted", "action":req.Action})
	case r.Method == http.MethodPost && len(parts) == 7 && parts[6] == "console":
		var req consoleRequest
		if err := decodeJSON(r.Body, &req); err != nil { writeError(w, http.StatusBadRequest, "invalid_json", err.Error()); return }
		ticket, err := s.vsphere.AcquireConsole(ctx, vcID, uuid)
		if err != nil { s.vsphereError(w, err); return }
		displayName := safeDisplayName(req.DisplayName)
		token, err := s.sessions.Create(consoleStore.Session{VCenterID:vcID, InstanceUUID:uuid, DisplayName:displayName, ServiceID:req.ServiceID, UserID:req.UserID, RemoteURL:ticket.URL})
		if err != nil { writeError(w, http.StatusInternalServerError, "session_error", "Unable to create console session"); return }
		s.audit.Write(map[string]any{"event":"console_ticket", "vcenter_id":vcID, "instance_uuid":uuid, "service_id":req.ServiceID, "user_id":req.UserID, "success":true, "remote_ip":clientIP(r)})
		writeJSON(w, http.StatusCreated, map[string]any{"launch_url":s.cfg.PublicBaseURL+"/console/launch/"+token, "expires_in":int(s.cfg.ConsoleTTL.Seconds())})
	default:
		writeError(w, http.StatusNotFound, "not_found", "Endpoint not found")
	}
}

func (s *server) requireHMAC(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, err := io.ReadAll(io.LimitReader(r.Body, 1<<20))
		if err != nil { writeError(w, http.StatusBadRequest, "body_error", "Unable to read request"); return }
		r.Body = io.NopCloser(bytes.NewReader(body))
		err = s.auth.Verify(r.Header.Get("X-VMControl-Key"), r.Header.Get("X-VMControl-Signature"), r.Method, r.URL.EscapedPath(), r.Header.Get("X-VMControl-Timestamp"), r.Header.Get("X-VMControl-Nonce"), body)
		if err != nil { s.audit.Write(map[string]any{"event":"auth_failure", "success":false, "error":err.Error(), "remote_ip":clientIP(r)}); writeError(w, http.StatusUnauthorized, "unauthorized", "Request authentication failed"); return }
		next.ServeHTTP(w, r)
	})
}

func (s *server) consoleLaunch(w http.ResponseWriter, r *http.Request) {
	token := r.PathValue("token")
	session, err := s.sessions.Launch(token)
	if err != nil { http.Error(w, "This console link is expired or has already been used.", http.StatusGone); return }
	http.SetCookie(w, &http.Cookie{Name:consoleCookie, Value:token, Path:"/", Secure:true, HttpOnly:true, SameSite:http.SameSiteStrictMode, MaxAge:int(time.Until(session.ExpiresAt).Seconds())})
	w.Header().Set("Cache-Control", "no-store")
	http.Redirect(w, r, "/console/view", http.StatusSeeOther)
}

func (s *server) consoleView(w http.ResponseWriter, r *http.Request) {
	cookie, err := r.Cookie(consoleCookie)
	if err != nil { http.Error(w, "Console session required.", http.StatusUnauthorized); return }
	session, err := s.sessions.View(cookie.Value)
	if err != nil { http.Error(w, "Console session expired.", http.StatusGone); return }
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	_ = s.template.Execute(w, map[string]any{"DisplayName": session.DisplayName})
}

func (s *server) webmksAsset(w http.ResponseWriter, r *http.Request) {
	if _, err := s.consoleSessionFromCookie(r); err != nil { http.Error(w, "Unauthorized", http.StatusUnauthorized); return }
	allowed := map[string]bool{"jquery.min.js":true, "jquery-ui.min.js":true, "wmks.min.js":true, "wmks-all.css":true}
	asset := r.PathValue("asset")
	if !allowed[asset] { http.NotFound(w, r); return }
	path := filepath.Join("/opt/webmks", asset)
	if _, err := os.Stat(path); err != nil { http.Error(w, "WebMKS SDK asset is not installed", http.StatusServiceUnavailable); return }
	w.Header().Set("Cache-Control", "private, max-age=3600")
	http.ServeFile(w, r, path)
}

func (s *server) localAsset(w http.ResponseWriter, r *http.Request) {
	if _, err := s.consoleSessionFromCookie(r); err != nil { http.Error(w, "Unauthorized", http.StatusUnauthorized); return }
	allowed := map[string]bool{"console.js":true, "console.css":true}
	asset := r.PathValue("asset")
	if !allowed[asset] { http.NotFound(w, r); return }
	w.Header().Set("Cache-Control", "private, max-age=3600")
	http.ServeFile(w, r, filepath.Join(s.cfg.StaticDirectory, asset))
}

func (s *server) consoleWebSocket(w http.ResponseWriter, r *http.Request) {
	if !s.validOrigin(r.Header.Get("Origin")) { http.Error(w, "Invalid origin", http.StatusForbidden); return }
	cookie, err := r.Cookie(consoleCookie)
	if err != nil { http.Error(w, "Unauthorized", http.StatusUnauthorized); return }
	session, err := s.sessions.Connect(cookie.Value)
	if err != nil { http.Error(w, "Console session unavailable", http.StatusGone); return }
	protocols := websocket.Subprotocols(r)
	upgrader := websocket.Upgrader{HandshakeTimeout:10*time.Second, Subprotocols:protocols, CheckOrigin:func(*http.Request) bool { return true }}
	client, err := upgrader.Upgrade(w, r, nil)
	if err != nil { return }
	defer client.Close()
	remoteTarget, err := s.resolveConsoleTarget(r.Context(), session.RemoteURL)
	if err != nil { _ = client.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(1008, "Console host is outside policy"), time.Now().Add(time.Second)); return }
	dialer := websocket.Dialer{HandshakeTimeout:10*time.Second, TLSClientConfig:&tls.Config{InsecureSkipVerify:true, MinVersion:tls.VersionTLS12}, Subprotocols:protocols}
	dialer.NetDialContext = func(ctx context.Context, network, address string) (net.Conn, error) {
		_, port, splitErr := net.SplitHostPort(address)
		if splitErr != nil { return nil, splitErr }
		return (&net.Dialer{Timeout:10*time.Second}).DialContext(ctx, network, net.JoinHostPort(remoteTarget, port))
	}
	remote, _, err := dialer.DialContext(r.Context(), session.RemoteURL, http.Header{"Origin":[]string{s.cfg.PublicBaseURL}})
	if err != nil { _ = client.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(1011, "Unable to connect to VM console"), time.Now().Add(time.Second)); return }
	defer remote.Close()
	s.audit.Write(map[string]any{"event":"console_connected", "vcenter_id":session.VCenterID, "instance_uuid":session.InstanceUUID, "service_id":session.ServiceID, "user_id":session.UserID, "success":true, "remote_ip":clientIP(r)})
	errCh := make(chan error, 2)
	go proxyMessages(remote, client, errCh)
	go proxyMessages(client, remote, errCh)
	<-errCh
}

func (s *server) resolveConsoleTarget(ctx context.Context, rawURL string) (string, error) {
	u, err := url.Parse(rawURL)
	if err != nil || u.Scheme != "wss" || u.Hostname() == "" {
		return "", errors.New("invalid console URL")
	}
	addresses, err := net.DefaultResolver.LookupIPAddr(ctx, u.Hostname())
	if err != nil {
		return "", err
	}
	for _, address := range addresses {
		for _, network := range s.cfg.ConsoleAllowedCIDRs {
			if network.Contains(address.IP) {
				return address.IP.String(), nil
			}
		}
	}
	return "", errors.New("console host is outside allowed networks")
}

func proxyMessages(dst, src *websocket.Conn, errCh chan<- error) {
	for { messageType, data, err := src.ReadMessage(); if err != nil { errCh <- err; return }; if err := dst.WriteMessage(messageType, data); err != nil { errCh <- err; return } }
}

func (s *server) consoleSessionFromCookie(r *http.Request) (*consoleStore.Session, error) {
	cookie, err := r.Cookie(consoleCookie); if err != nil { return nil, err }; return s.sessions.View(cookie.Value)
}

func (s *server) validOrigin(origin string) bool {
	o, err := url.Parse(origin); if err != nil { return false }; base, _ := url.Parse(s.cfg.PublicBaseURL); return o.Scheme == base.Scheme && o.Host == base.Host
}

func (s *server) vmLock(key string) *sync.Mutex { s.locksMu.Lock(); defer s.locksMu.Unlock(); if s.locks[key] == nil { s.locks[key] = &sync.Mutex{} }; return s.locks[key] }
func (s *server) vsphereError(w http.ResponseWriter, err error) { code := http.StatusBadGateway; if strings.Contains(strings.ToLower(err.Error()), "not found") { code=http.StatusNotFound }; writeError(w, code, "vsphere_error", err.Error()) }

func securityHeaders(next http.Handler) http.Handler { return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Header().Set("X-Content-Type-Options","nosniff"); w.Header().Set("X-Frame-Options","DENY"); w.Header().Set("Referrer-Policy","no-referrer"); w.Header().Set("Permissions-Policy","camera=(), microphone=(), geolocation=()"); w.Header().Set("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self' wss:; img-src 'self' data:; frame-ancestors 'none'"); next.ServeHTTP(w,r) }) }
func decodeJSON(r io.Reader, target any) error { d:=json.NewDecoder(io.LimitReader(r,65536)); d.DisallowUnknownFields(); return d.Decode(target) }
func writeJSON(w http.ResponseWriter, status int, value any) { w.Header().Set("Content-Type","application/json"); w.Header().Set("Cache-Control","no-store"); w.WriteHeader(status); _=json.NewEncoder(w).Encode(value) }
func writeError(w http.ResponseWriter, status int, code, message string) { writeJSON(w,status,map[string]any{"error":map[string]string{"code":code,"message":message}}) }
func validID(v string) bool { if len(v)<1||len(v)>40{return false}; for _,r:=range v { if !(r=='-'||r=='_'||r>='a'&&r<='z'||r>='A'&&r<='Z'||r>='0'&&r<='9'){return false} }; return true }
func validUUID(v string) bool { if len(v)!=36{return false}; for i,r:=range v { if i==8||i==13||i==18||i==23 { if r!='-'{return false};continue }; if !strings.ContainsRune("0123456789abcdefABCDEF",r){return false} }; return true }
func clientIP(r *http.Request) string { if v:=r.Header.Get("X-Forwarded-For");v!=""{return strings.TrimSpace(strings.Split(v,",")[0])}; host,_,_:=net.SplitHostPort(r.RemoteAddr);return host }
func errorString(err error) string { if err==nil{return ""};return err.Error() }

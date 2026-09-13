package main

import (
	"context"
	"net/http"
	"strings"
	"unicode"
	"unicode/utf8"
)

type consoleActionRequest struct {
	Action string `json:"action"`
}

func safeDisplayName(value string) string {
	value = strings.TrimSpace(value)
	if value == "" || !utf8.ValidString(value) || utf8.RuneCountInString(value) > 80 {
		return "سرور مجازی"
	}
	value = strings.Map(func(r rune) rune {
		if unicode.IsControl(r) || r == '<' || r == '>' {
			return -1
		}
		return r
	}, value)
	value = strings.TrimSpace(value)
	if value == "" {
		return "سرور مجازی"
	}
	return value
}

func (s *server) consoleStatus(w http.ResponseWriter, r *http.Request) {
	session, err := s.consoleSessionFromCookie(r)
	if err != nil {
		writeError(w, http.StatusGone, "session_expired", "Console session expired")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), s.cfg.VCenterTimeout)
	defer cancel()
	info, err := s.vsphere.Info(ctx, session.VCenterID, session.InstanceUUID)
	if err != nil {
		s.vsphereError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"power_state":           info.PowerState,
		"safe_actions_available": info.ToolsStatus == "guestToolsRunning",
	})
}

func (s *server) consoleAction(w http.ResponseWriter, r *http.Request) {
	if !s.validOrigin(r.Header.Get("Origin")) {
		writeError(w, http.StatusForbidden, "invalid_origin", "Invalid request origin")
		return
	}
	session, err := s.consoleSessionFromCookie(r)
	if err != nil {
		writeError(w, http.StatusGone, "session_expired", "Console session expired")
		return
	}
	var req consoleActionRequest
	if err := decodeJSON(r.Body, &req); err != nil {
		writeError(w, http.StatusBadRequest, "invalid_json", "Invalid action request")
		return
	}
	allowed := map[string]bool{"power_on": true, "shutdown": true, "reboot": true}
	if !allowed[req.Action] || !s.cfg.AllowedActions[req.Action] {
		writeError(w, http.StatusForbidden, "action_disabled", "Action is disabled by gateway policy")
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), s.cfg.VCenterTimeout)
	defer cancel()
	lock := s.vmLock(session.VCenterID + ":" + session.InstanceUUID)
	lock.Lock()
	defer lock.Unlock()
	err = s.vsphere.Action(ctx, session.VCenterID, session.InstanceUUID, req.Action)
	s.audit.Write(map[string]any{
		"event": "console_vm_action", "vcenter_id": session.VCenterID,
		"instance_uuid": session.InstanceUUID, "service_id": session.ServiceID,
		"user_id": session.UserID, "action": req.Action, "success": err == nil,
		"error": errorString(err), "remote_ip": clientIP(r),
	})
	if err != nil {
		s.vsphereError(w, err)
		return
	}
	writeJSON(w, http.StatusAccepted, map[string]any{"status": "accepted", "action": req.Action})
}

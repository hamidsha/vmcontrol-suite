package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
	"time"
)

type VCenter struct {
	ID                       string   `json:"id"`
	URL                      string   `json:"url"`
	Username                 string   `json:"username"`
	Password                 string   `json:"password"`
	TLSFingerprintSHA256     string   `json:"tls_fingerprint_sha256"`
	AllowedInventoryPrefixes []string `json:"allowed_inventory_prefixes"`
}

type Config struct {
	ListenAddress     string
	PublicBaseURL     string
	APIKey            string
	APISecret         string
	VCenterConfigPath string
	AuditLogPath      string
	StaticDirectory   string
	RequestSkew       time.Duration
	ConsoleTTL        time.Duration
	ConsoleMaxAge     time.Duration
	VCenterTimeout    time.Duration
	AllowedActions    map[string]bool
	ConsoleAllowedCIDRs []*net.IPNet
	VCenters          map[string]VCenter
}

func Load() (*Config, error) {
	c := &Config{
		ListenAddress:     env("LISTEN_ADDRESS", ":8080"),
		PublicBaseURL:     strings.TrimRight(env("PUBLIC_BASE_URL", ""), "/"),
		APIKey:            os.Getenv("API_KEY"),
		APISecret:         os.Getenv("API_SECRET"),
		VCenterConfigPath: env("VCENTERS_FILE", "/run/secrets/vcenters.json"),
		AuditLogPath:      env("AUDIT_LOG_PATH", "/data/audit.jsonl"),
		StaticDirectory:   env("STATIC_DIRECTORY", "/app/static"),
		RequestSkew:       duration("REQUEST_MAX_SKEW", 5*time.Minute),
		ConsoleTTL:        duration("CONSOLE_LAUNCH_TTL", 60*time.Second),
		ConsoleMaxAge:     duration("CONSOLE_MAX_AGE", 30*time.Minute),
		VCenterTimeout:    duration("VCENTER_TIMEOUT", 30*time.Second),
		AllowedActions:    csvSet(env("ALLOWED_ACTIONS", "power_on,shutdown,reboot")),
		VCenters:          make(map[string]VCenter),
	}
	var cidrErr error
	c.ConsoleAllowedCIDRs, cidrErr = parseCIDRs(env("CONSOLE_ALLOWED_CIDRS", "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"))
	if cidrErr != nil {
		return nil, cidrErr
	}

	if c.PublicBaseURL == "" || c.APIKey == "" || len(c.APISecret) < 32 {
		return nil, errors.New("PUBLIC_BASE_URL, API_KEY and an API_SECRET of at least 32 characters are required")
	}
	if u, err := url.Parse(c.PublicBaseURL); err != nil || u.Scheme != "https" || u.Host == "" {
		return nil, errors.New("PUBLIC_BASE_URL must be a valid https URL")
	}

	b, err := os.ReadFile(c.VCenterConfigPath)
	if err != nil {
		return nil, fmt.Errorf("read vCenter config: %w", err)
	}
	var entries []VCenter
	if err := json.Unmarshal(b, &entries); err != nil {
		return nil, fmt.Errorf("parse vCenter config: %w", err)
	}
	for _, vc := range entries {
		vc.ID = strings.TrimSpace(vc.ID)
		vc.URL = strings.TrimSpace(vc.URL)
		if vc.ID == "" || vc.URL == "" || vc.Username == "" || vc.Password == "" || vc.TLSFingerprintSHA256 == "" {
			return nil, fmt.Errorf("vCenter entry %q is incomplete", vc.ID)
		}
		if _, exists := c.VCenters[vc.ID]; exists {
			return nil, fmt.Errorf("duplicate vCenter id %q", vc.ID)
		}
		c.VCenters[vc.ID] = vc
	}
	if len(c.VCenters) == 0 {
		return nil, errors.New("at least one vCenter is required")
	}
	return c, nil
}

func parseCIDRs(value string) ([]*net.IPNet, error) {
	var result []*net.IPNet
	for _, item := range strings.Split(value, ",") {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		_, network, err := net.ParseCIDR(item)
		if err != nil {
			return nil, fmt.Errorf("invalid CONSOLE_ALLOWED_CIDRS entry %q", item)
		}
		result = append(result, network)
	}
	if len(result) == 0 {
		return nil, errors.New("CONSOLE_ALLOWED_CIDRS must contain at least one network")
	}
	return result, nil
}

func csvSet(value string) map[string]bool {
	result := make(map[string]bool)
	for _, item := range strings.Split(value, ",") {
		if item = strings.TrimSpace(item); item != "" {
			result[item] = true
		}
	}
	return result
}

func env(name, fallback string) string {
	if v := strings.TrimSpace(os.Getenv(name)); v != "" {
		return v
	}
	return fallback
}

func duration(name string, fallback time.Duration) time.Duration {
	v := strings.TrimSpace(os.Getenv(name))
	if v == "" {
		return fallback
	}
	d, err := time.ParseDuration(v)
	if err != nil {
		return fallback
	}
	return d
}

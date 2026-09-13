package console

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"sync"
	"time"
)

type Session struct {
	VCenterID   string
	InstanceUUID string
	DisplayName  string
	ServiceID   int64
	UserID      int64
	RemoteURL   string
	CreatedAt   time.Time
	LaunchBy    time.Time
	ExpiresAt   time.Time
	Launched    bool
	Connected   bool
}

type Store struct {
	mu       sync.Mutex
	sessions map[string]*Session
	launchTTL time.Duration
	maxAge   time.Duration
}

func NewStore(launchTTL, maxAge time.Duration) *Store {
	return &Store{sessions: make(map[string]*Session), launchTTL: launchTTL, maxAge: maxAge}
}

func (s *Store) Create(session Session) (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil { return "", err }
	token := base64.RawURLEncoding.EncodeToString(b)
	now := time.Now().UTC()
	session.CreatedAt = now
	session.LaunchBy = now.Add(s.launchTTL)
	session.ExpiresAt = now.Add(s.maxAge)
	s.mu.Lock()
	s.cleanupLocked(now)
	s.sessions[digest(token)] = &session
	s.mu.Unlock()
	return token, nil
}

func (s *Store) Launch(token string) (*Session, error) {
	now := time.Now().UTC()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked(now)
	session, ok := s.sessions[digest(token)]
	if !ok || now.After(session.LaunchBy) { return nil, errors.New("console link expired or invalid") }
	if session.Launched { return nil, errors.New("console link has already been used") }
	session.Launched = true
	copy := *session
	return &copy, nil
}

func (s *Store) View(token string) (*Session, error) {
	now := time.Now().UTC()
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cleanupLocked(now)
	session, ok := s.sessions[digest(token)]
	if !ok || !session.Launched || now.After(session.ExpiresAt) { return nil, errors.New("console session expired") }
	copy := *session
	return &copy, nil
}

func (s *Store) Connect(token string) (*Session, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	session, ok := s.sessions[digest(token)]
	if !ok || !session.Launched || session.Connected || time.Now().UTC().After(session.ExpiresAt) {
		return nil, errors.New("console session is unavailable")
	}
	session.Connected = true
	copy := *session
	return &copy, nil
}

func (s *Store) Delete(token string) {
	s.mu.Lock()
	delete(s.sessions, digest(token))
	s.mu.Unlock()
}

func (s *Store) cleanupLocked(now time.Time) {
	for key, session := range s.sessions {
		if now.After(session.ExpiresAt) || (!session.Launched && now.After(session.LaunchBy)) { delete(s.sessions, key) }
	}
}

func digest(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}

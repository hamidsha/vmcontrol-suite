package auth

import (
	"testing"
	"time"
)

func TestVerifierAcceptsOnce(t *testing.T) {
	v := NewVerifier("key", "01234567890123456789012345678901", time.Minute)
	now := time.Unix(1_700_000_000, 0)
	v.now = func() time.Time { return now }
	body := []byte(`{"ok":true}`)
	ts := "1700000000"
	nonce := "0123456789abcdef"
	sig := Sign("01234567890123456789012345678901", "POST", "/api/v1/test", ts, nonce, body)
	if err := v.Verify("key", sig, "POST", "/api/v1/test", ts, nonce, body); err != nil {
		t.Fatalf("first verification failed: %v", err)
	}
	if err := v.Verify("key", sig, "POST", "/api/v1/test", ts, nonce, body); err == nil {
		t.Fatal("replay was accepted")
	}
}

func TestVerifierRejectsChangedBody(t *testing.T) {
	v := NewVerifier("key", "01234567890123456789012345678901", time.Minute)
	now := time.Unix(1_700_000_000, 0)
	v.now = func() time.Time { return now }
	ts := "1700000000"
	nonce := "fedcba9876543210"
	sig := Sign("01234567890123456789012345678901", "POST", "/api/v1/test", ts, nonce, []byte(`{"ok":true}`))
	if err := v.Verify("key", sig, "POST", "/api/v1/test", ts, nonce, []byte(`{"ok":false}`)); err == nil {
		t.Fatal("changed body was accepted")
	}
}

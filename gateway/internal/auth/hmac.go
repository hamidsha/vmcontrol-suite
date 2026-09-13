package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"sync"
	"time"
)

type Verifier struct {
	key    string
	secret []byte
	skew   time.Duration
	mu     sync.Mutex
	nonces map[string]time.Time
	now    func() time.Time
}

func NewVerifier(key, secret string, skew time.Duration) *Verifier {
	return &Verifier{key: key, secret: []byte(secret), skew: skew, nonces: make(map[string]time.Time), now: time.Now}
}

func Canonical(method, path, timestamp, nonce string, body []byte) string {
	sum := sha256.Sum256(body)
	return strings.Join([]string{strings.ToUpper(method), path, timestamp, nonce, hex.EncodeToString(sum[:])}, "\n")
}

func Sign(secret, method, path, timestamp, nonce string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(Canonical(method, path, timestamp, nonce, body)))
	return hex.EncodeToString(mac.Sum(nil))
}

func (v *Verifier) Verify(key, signature, method, path, timestamp, nonce string, body []byte) error {
	if !hmac.Equal([]byte(key), []byte(v.key)) {
		return errors.New("unknown API key")
	}
	if len(nonce) < 16 || len(nonce) > 128 {
		return errors.New("invalid nonce")
	}
	unix, err := strconv.ParseInt(timestamp, 10, 64)
	if err != nil {
		return errors.New("invalid timestamp")
	}
	now := v.now()
	requestTime := time.Unix(unix, 0)
	if requestTime.Before(now.Add(-v.skew)) || requestTime.After(now.Add(v.skew)) {
		return errors.New("request timestamp outside allowed window")
	}
	expected := Sign(string(v.secret), method, path, timestamp, nonce, body)
	provided, err := hex.DecodeString(signature)
	expectedBytes, _ := hex.DecodeString(expected)
	if err != nil || !hmac.Equal(expectedBytes, provided) {
		return errors.New("invalid signature")
	}

	v.mu.Lock()
	defer v.mu.Unlock()
	for n, expiry := range v.nonces {
		if now.After(expiry) {
			delete(v.nonces, n)
		}
	}
	compound := fmt.Sprintf("%s:%s", key, nonce)
	if _, exists := v.nonces[compound]; exists {
		return errors.New("replayed nonce")
	}
	v.nonces[compound] = now.Add(v.skew)
	return nil
}

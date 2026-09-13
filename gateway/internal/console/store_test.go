package console

import (
	"testing"
	"time"
)

func TestLaunchAndConnectAreOneTime(t *testing.T) {
	store := NewStore(time.Minute, 10*time.Minute)
	token, err := store.Create(Session{InstanceUUID: "11111111-2222-3333-4444-555555555555"})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := store.Launch(token); err != nil {
		t.Fatalf("first launch failed: %v", err)
	}
	if _, err := store.Launch(token); err == nil {
		t.Fatal("second launch was accepted")
	}
	if _, err := store.Connect(token); err != nil {
		t.Fatalf("first connection failed: %v", err)
	}
	if _, err := store.Connect(token); err == nil {
		t.Fatal("second connection was accepted")
	}
}

package vsphere

import "testing"

func TestAllowedPath(t *testing.T) {
	prefixes := []string{"/Datacenter/vm/Customer-VMs/"}
	if !allowedPath("/Datacenter/vm/Customer-VMs/vm-1", prefixes) {
		t.Fatal("expected customer path to be allowed")
	}
	if allowedPath("/Datacenter/vm/Internal/vcenter", prefixes) {
		t.Fatal("expected internal path to be denied")
	}
}

func TestNormalizeFingerprint(t *testing.T) {
	got := canonicalFingerprint("AA:bb-CC dd")
	if got != "AA:BB:CC:DD" {
		t.Fatalf("unexpected normalized fingerprint: %s", got)
	}
}

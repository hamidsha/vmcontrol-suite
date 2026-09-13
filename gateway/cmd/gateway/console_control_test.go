package main

import "testing"

func TestSafeDisplayName(t *testing.T) {
	tests := []struct {
		input string
		want  string
	}{
		{"  Database Server  ", "Database Server"},
		{"<script>alert(1)</script>", "scriptalert(1)/script"},
		{"\x00\n", "سرور مجازی"},
		{"", "سرور مجازی"},
	}
	for _, test := range tests {
		if got := safeDisplayName(test.input); got != test.want {
			t.Fatalf("safeDisplayName(%q) = %q, want %q", test.input, got, test.want)
		}
	}
}

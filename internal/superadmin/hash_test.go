package superadmin

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestComputeHash(t *testing.T) {
	tests := []struct {
		wantErr   error
		name      string
		userID    string
		userLogin string
		salt      string
		wantHash  string
	}{
		{
			name:      "valid credentials",
			userID:    "1",
			userLogin: "admin",
			salt:      "secret_salt",
			wantHash:  "149f4870f5abf72f266229505f4cc293b1a436d94ade38ebb957195b7bceb58a9029943e6d14bcbd0d5a9c79930a30bec1e4667da4c5e2c3b0cd50e46f8fcd45",
			wantErr:   nil,
		},
		{
			name:      "another user credentials",
			userID:    "42",
			userLogin: "site_owner",
			salt:      "k8s-production-salt-key-9988",
			wantHash:  "eb930a2224d1a563b277b3d3abba38310e38645878656b70b7c6be8c8a24e6e31aa31f20cebfb87a989f9f3019633cfaee56f40e1e761d308439de01967018ad",
			wantErr:   nil,
		},
		{
			name:      "uppercase login normalized",
			userID:    "1",
			userLogin: "ADMIN",
			salt:      "secret_salt",
			wantHash:  "149f4870f5abf72f266229505f4cc293b1a436d94ade38ebb957195b7bceb58a9029943e6d14bcbd0d5a9c79930a30bec1e4667da4c5e2c3b0cd50e46f8fcd45",
			wantErr:   nil,
		},
		{
			name:      "zero user ID",
			userID:    "0",
			userLogin: "zero_user",
			salt:      "salt_zero",
			wantHash:  "",
			wantErr:   ErrInvalidUserID,
		},
		{
			name:      "negative user ID",
			userID:    "-1",
			userLogin: "neg_user",
			salt:      "salt_neg",
			wantHash:  "",
			wantErr:   ErrInvalidUserID,
		},
		{
			name:      "non-numeric user ID",
			userID:    "1:admin",
			userLogin: "colon_user",
			salt:      "salt_colon",
			wantHash:  "",
			wantErr:   ErrInvalidUserID,
		},
		{
			name:      "empty user ID",
			userID:    "",
			userLogin: "editor_user",
			salt:      "salt_alpha",
			wantHash:  "",
			wantErr:   ErrEmptyUserID,
		},
		{
			name:      "whitespace only user ID",
			userID:    "   ",
			userLogin: "manager_user",
			salt:      "salt_beta",
			wantHash:  "",
			wantErr:   ErrEmptyUserID,
		},
		{
			name:      "empty user login",
			userID:    "10",
			userLogin: "",
			salt:      "salt_gamma",
			wantHash:  "",
			wantErr:   ErrEmptyUserLogin,
		},
		{
			name:      "whitespace only user login",
			userID:    "11",
			userLogin: "\t\n",
			salt:      "salt_delta",
			wantHash:  "",
			wantErr:   ErrEmptyUserLogin,
		},
		{
			name:      "empty salt",
			userID:    "12",
			userLogin: "support_user",
			salt:      "",
			wantHash:  "",
			wantErr:   ErrEmptySalt,
		},
		{
			name:      "whitespace only salt",
			userID:    "13",
			userLogin: "guest_user",
			salt:      "   \n",
			wantHash:  "",
			wantErr:   ErrEmptySalt,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, err := ComputeHash(tc.userID, tc.userLogin, tc.salt)
			if tc.wantErr != nil {
				if !errors.Is(err, tc.wantErr) {
					t.Fatalf("expected error %v, got %v", tc.wantErr, err)
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != tc.wantHash {
				t.Fatalf("expected hash %s, got %s", tc.wantHash, got)
			}
		})
	}
}

func TestReadSaltFromFile(t *testing.T) {
	tempDir := t.TempDir()
	validSaltPath := filepath.Join(tempDir, "auth_salt.txt")
	if err := os.WriteFile(validSaltPath, []byte("  my-secure-production-salt  \n"), 0o600); err != nil {
		t.Fatalf("failed to create test salt file: %v", err)
	}

	emptySaltPath := filepath.Join(tempDir, "empty_salt.txt")
	if err := os.WriteFile(emptySaltPath, []byte("   \n"), 0o600); err != nil {
		t.Fatalf("failed to create empty salt file: %v", err)
	}

	nonExistentPath := filepath.Join(tempDir, "non_existent.txt")

	ctx := context.Background()

	tests := []struct {
		ctx      context.Context
		name     string
		path     string
		wantSalt string
		wantErr  bool
	}{
		{
			name:     "valid file with whitespace trimming",
			ctx:      ctx,
			path:     validSaltPath,
			wantSalt: "my-secure-production-salt",
			wantErr:  false,
		},
		{
			name:     "empty file error",
			ctx:      ctx,
			path:     emptySaltPath,
			wantSalt: "",
			wantErr:  true,
		},
		{
			name:     "file does not exist",
			ctx:      ctx,
			path:     nonExistentPath,
			wantSalt: "",
			wantErr:  true,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got, err := ReadSaltFromFile(tc.ctx, tc.path)
			if tc.wantErr {
				if err == nil {
					t.Fatalf("expected error, got nil")
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			if got != tc.wantSalt {
				t.Fatalf("expected salt %q, got %q", tc.wantSalt, got)
			}
		})
	}

	t.Run("cancelled context", func(t *testing.T) {
		canceledCtx, cancel := context.WithCancel(context.Background())
		cancel()

		_, err := ReadSaltFromFile(canceledCtx, validSaltPath)
		if err == nil {
			t.Fatalf("expected error on cancelled context, got nil")
		}
	})
}

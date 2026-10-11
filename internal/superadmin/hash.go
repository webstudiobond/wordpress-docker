// Package superadmin provides zero-knowledge authentication hashing for WordPress administrators.
package superadmin

import (
	"context"
	"crypto/hmac"
	"crypto/sha512"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// Validation errors for superadmin credentials and secret salt inputs.
var (
	ErrEmptyUserID    = errors.New("user ID must not be empty")
	ErrInvalidUserID  = errors.New("user ID must be a positive integer")
	ErrEmptyUserLogin = errors.New("user login must not be empty")
	ErrEmptySalt      = errors.New("salt must not be empty")
)

// ComputeHash generates an HMAC-SHA512 hex digest for a WordPress user identity using the provided salt.
func ComputeHash(userID, userLogin, salt string) (string, error) {
	trimmedID := strings.TrimSpace(userID)
	if trimmedID == "" {
		return "", ErrEmptyUserID
	}
	parsedID, err := strconv.ParseUint(trimmedID, 10, 64)
	if err != nil || parsedID == 0 {
		return "", ErrInvalidUserID
	}

	trimmedLogin := strings.ToLower(strings.TrimSpace(userLogin))
	if trimmedLogin == "" {
		return "", ErrEmptyUserLogin
	}
	trimmedSalt := strings.TrimSpace(salt)
	if trimmedSalt == "" {
		return "", ErrEmptySalt
	}

	mac := hmac.New(sha512.New, []byte(trimmedSalt))
	payload := fmt.Sprintf("%d:%s", parsedID, trimmedLogin)
	mac.Write([]byte(payload))

	return hex.EncodeToString(mac.Sum(nil)), nil
}

// ReadSaltFromFile reads an authentication salt from the specified filesystem path and trims surrounding whitespace.
func ReadSaltFromFile(ctx context.Context, saltFilePath string) (string, error) {
	if err := ctx.Err(); err != nil {
		return "", fmt.Errorf("context cancelled before reading salt file: %w", err)
	}

	cleanedPath := filepath.Clean(saltFilePath)
	content, err := os.ReadFile(cleanedPath)
	if err != nil {
		return "", fmt.Errorf("failed to read salt file %s: %w", cleanedPath, err)
	}

	trimmed := strings.TrimSpace(string(content))
	if trimmed == "" {
		return "", ErrEmptySalt
	}

	return trimmed, nil
}

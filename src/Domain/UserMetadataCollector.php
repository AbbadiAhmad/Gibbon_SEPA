<?php
namespace Gibbon\Module\Sepa\Domain;

/**
 * User Metadata Collector
 *
 * Collects comprehensive user metadata for audit trail and proof of action.
 * Captures IP address, user agent, timezone, language, and other contextual data.
 *
 * @version v2.1.1
 * @since   v2.1.1
 */
class UserMetadataCollector
{
    /**
     * Get the real client IP address
     * Handles proxies and load balancers
     *
     * @return string|null
     */
    public static function getClientIP(): ?string
    {
        // Check for IP from various headers (in order of preference)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',      // CloudFlare
            'HTTP_X_REAL_IP',              // Nginx proxy
            'HTTP_X_FORWARDED_FOR',        // Proxy
            'HTTP_CLIENT_IP',              // Shared internet
            'REMOTE_ADDR'                  // Direct connection
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated IPs (take the first one)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }

                // Also accept private IPs if no public IP found (for internal networks)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * Get user agent string
     *
     * @return string|null
     */
    public static function getUserAgent(): ?string
    {
        return !empty($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null;
    }

    /**
     * Get comprehensive metadata as JSON
     * Includes timezone, language preferences, referer, and more
     *
     * @param int|null $gibbonPersonID Optional person ID for additional context
     * @return array
     */
    public static function getMetadata(?int $gibbonPersonID = null): array
    {
        $metadata = [
            'timestamp' => date('Y-m-d H:i:s'),
            'timestamp_utc' => gmdate('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get(),
            'ip' => self::getClientIP(),
            'user_agent' => self::getUserAgent(),
        ];

        // HTTP headers
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $metadata['accept_language'] = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 100);
        }

        if (!empty($_SERVER['HTTP_REFERER'])) {
            $metadata['referer'] = substr($_SERVER['HTTP_REFERER'], 0, 500);
        }

        if (!empty($_SERVER['HTTP_ACCEPT'])) {
            $metadata['accept'] = substr($_SERVER['HTTP_ACCEPT'], 0, 200);
        }

        // Request details
        $metadata['request_method'] = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $metadata['request_uri'] = $_SERVER['REQUEST_URI'] ?? '';
        $metadata['server_protocol'] = $_SERVER['SERVER_PROTOCOL'] ?? '';

        // HTTPS detection
        $metadata['is_https'] = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        );

        // Server details (for multi-server environments)
        if (!empty($_SERVER['SERVER_NAME'])) {
            $metadata['server_name'] = $_SERVER['SERVER_NAME'];
        }

        if (!empty($_SERVER['SERVER_ADDR'])) {
            $metadata['server_ip'] = $_SERVER['SERVER_ADDR'];
        }

        // Session ID (anonymized for security)
        if (session_id()) {
            $metadata['session_hash'] = hash('sha256', session_id());
        }

        // Person ID if provided
        if ($gibbonPersonID !== null) {
            $metadata['gibbonPersonID'] = $gibbonPersonID;
        }

        // Browser fingerprint components
        $metadata['fingerprint'] = self::generateFingerprint();

        return $metadata;
    }

    /**
     * Generate a browser fingerprint from available data
     * This creates a reasonably unique identifier without cookies
     *
     * @return string
     */
    public static function generateFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get all metadata including IP and user agent (convenience method)
     * Returns everything needed for a database record
     *
     * @param int|null $gibbonPersonID
     * @return array with keys: ip, user_agent, metadata_json
     */
    public static function collectAll(?int $gibbonPersonID = null): array
    {
        $metadata = self::getMetadata($gibbonPersonID);

        return [
            'ip' => self::getClientIP(),
            'user_agent' => self::getUserAgent(),
            'metadata_json' => json_encode($metadata, JSON_PRETTY_PRINT)
        ];
    }

    /**
     * Parse and display metadata in human-readable format
     *
     * @param string|null $metadataJson
     * @return string HTML formatted metadata
     */
    public static function formatMetadataForDisplay(?string $metadataJson): string
    {
        if (empty($metadataJson)) {
            return '<em>No metadata available</em>';
        }

        $metadata = json_decode($metadataJson, true);
        if (!$metadata) {
            return '<em>Invalid metadata</em>';
        }

        $html = '<table class="smallIntBorder" style="width: 100%;">';

        // Important fields first
        $importantFields = [
            'timestamp' => 'Timestamp',
            'ip' => 'IP Address',
            'timezone' => 'Timezone',
            'accept_language' => 'Language',
            'is_https' => 'Secure Connection',
            'fingerprint' => 'Browser Fingerprint'
        ];

        foreach ($importantFields as $key => $label) {
            if (isset($metadata[$key])) {
                $value = $metadata[$key];
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                $html .= '<tr>';
                $html .= '<td style="width: 30%; font-weight: bold;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Validate that metadata appears authentic and complete
     * Useful for detecting suspicious submissions
     *
     * @param string|null $metadataJson
     * @return array ['valid' => bool, 'warnings' => array]
     */
    public static function validateMetadata(?string $metadataJson): array
    {
        $warnings = [];

        if (empty($metadataJson)) {
            return ['valid' => false, 'warnings' => ['No metadata provided']];
        }

        $metadata = json_decode($metadataJson, true);
        if (!$metadata) {
            return ['valid' => false, 'warnings' => ['Invalid JSON metadata']];
        }

        // Check for required fields
        $requiredFields = ['timestamp', 'ip', 'user_agent'];
        foreach ($requiredFields as $field) {
            if (empty($metadata[$field])) {
                $warnings[] = "Missing required field: {$field}";
            }
        }

        // Check for suspicious patterns
        if (isset($metadata['user_agent'])) {
            $ua = strtolower($metadata['user_agent']);
            // Very short user agents are suspicious
            if (strlen($ua) < 10) {
                $warnings[] = 'Suspiciously short user agent';
            }
            // Common bot patterns
            if (preg_match('/bot|crawler|spider|scraper/i', $ua)) {
                $warnings[] = 'User agent indicates automated access';
            }
        }

        // Check IP validity
        if (isset($metadata['ip'])) {
            if (!filter_var($metadata['ip'], FILTER_VALIDATE_IP)) {
                $warnings[] = 'Invalid IP address format';
            }
        }

        return [
            'valid' => empty($warnings),
            'warnings' => $warnings
        ];
    }
}

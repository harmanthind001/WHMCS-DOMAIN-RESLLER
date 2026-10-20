<?php

namespace WHMCS\Module\Registrar\Hostopy;

final class Request
{
    public static function call(array $params, string $method, string $path, array $body = []): array
    {
        $endpoint = self::normalizeEndpoint((string) ($params["apiEndpoint"] ?? "/modules/addons/domainsreseller/api.php"), $params);
        $apiKey = (string) ($params["apiKey"] ?? "");
        $method = strtoupper($method);

        // First attempt with header auth only (safer with WAF/Cloudflare).
        $siteUrl = self::resolveSiteUrl($params);
        $result = self::perform($endpoint, $apiKey, $method, $path, $body, false, $siteUrl);
        if (empty($result["retry_with_fallback"])) {
            return $result["payload"];
        }

        // Retry with query/body api_key fallback only when header auth got 401.
        $retry = self::perform($endpoint, $apiKey, $method, $path, $body, true, $siteUrl);
        return $retry["payload"];
    }

    private static function perform(string $endpoint, string $apiKey, string $method, string $path, array $body, bool $useFallback, string $siteUrl = ""): array
    {
        $url = self::buildUrl($endpoint, $path);
        $requestBody = $body;

        if ($useFallback && $apiKey !== "") {
            if ($method === "GET" || $method === "DELETE") {
                $separator = strpos($url, "?") === false ? "?" : "&";
                $url .= $separator . "api_key=" . rawurlencode($apiKey);
            } elseif (!isset($requestBody["api_key"])) {
                $requestBody["api_key"] = $apiKey;
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; WHMCS-DomainsReseller/1.0; +https://www.hostopy.com)");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $headers = [
            "Accept: application/json",
            "Content-Type: application/json",
            "X-Requested-With: XMLHttpRequest",
            "X-API-Key: " . $apiKey,
        ];
        if ($siteUrl !== "") {
            $headers[] = "X-Reseller-Site: " . $siteUrl;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($requestBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== "") {
            return ["payload" => ["error" => "API request failed: " . $error . " (URL: " . $url . ")"], "retry_with_fallback" => false];
        }

        $rawHeaders = (string) substr((string) $raw, 0, $headerSize);
        $rawBody = (string) substr((string) $raw, $headerSize);
        if (
            $httpCode === 403
            && (
                stripos($rawHeaders, "cf-mitigated: challenge") !== false
                || stripos($rawBody, "Just a moment") !== false
                || stripos($rawBody, "__cf_chl_opt") !== false
            )
        ) {
            return [
                "payload" => [
                    "error" => "Cloudflare blocked API request (HTTP 403 challenge). In Cloudflare, add WAF/Firewall Skip rule for /modules/addons/domainsreseller/api.php and allow server-to-server requests.",
                ],
                "retry_with_fallback" => false,
            ];
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $snippet = trim(preg_replace('/\s+/', ' ', substr($rawBody, 0, 180)) ?? "");
            return ["payload" => ["error" => "Invalid API response (HTTP " . $httpCode . ", URL: " . $url . ", BODY: " . $snippet . ")"], "retry_with_fallback" => false];
        }

        if (!empty($decoded["success"])) {
            return ["payload" => ($decoded["data"] ?? ["success" => true]), "retry_with_fallback" => false];
        }

        if (!$useFallback && $httpCode === 401) {
            return ["payload" => ["error" => (string) ($decoded["error"] ?? "Unauthorized")], "retry_with_fallback" => true];
        }

        return ["payload" => ["error" => (string) ($decoded["error"] ?? "Request failed")], "retry_with_fallback" => false];
    }


    private static function resolveSiteUrl(array $params): string
    {
        foreach (["SystemURL", "systemurl", "systemURL"] as $key) {
            if (!empty($params[$key])) {
                return rtrim((string) $params[$key], "/");
            }
        }

        if (!empty($_SERVER["HTTP_HOST"])) {
            $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
            return $scheme . "://" . rtrim((string) $_SERVER["HTTP_HOST"], "/");
        }

        return "";
    }

    private static function normalizeEndpoint(string $rawEndpoint, array $params): string
    {
        $originEndpoint = trim((string) ($params["originEndpoint"] ?? ""));
        if ($originEndpoint !== "") {
            $rawEndpoint = $originEndpoint;
        }

        $rawEndpoint = trim($rawEndpoint);
        if ($rawEndpoint === "") {
            $rawEndpoint = "/modules/addons/domainsreseller/api.php";
        }

        if (strpos($rawEndpoint, "://") === false) {
            $systemUrl = rtrim((string) ($params["systemurl"] ?? ""), "/");
            if ($systemUrl === "") {
                $scheme = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" ? "https" : "http";
                $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
                $systemUrl = $scheme . "://" . $host;
            }
            $rawEndpoint = $systemUrl . "/" . ltrim($rawEndpoint, "/");
        }

        // Admin path (/deep) is often mistakenly included before /modules.
        if (strpos($rawEndpoint, "/deep/modules/") !== false) {
            $rawEndpoint = str_replace("/deep/modules/", "/modules/", $rawEndpoint);
        }

        $parts = parse_url($rawEndpoint);
        if (is_array($parts)) {
            $host = (string) ($parts["host"] ?? "");
            $path = (string) ($parts["path"] ?? "");
            $currentHost = (string) ($_SERVER["HTTP_HOST"] ?? "");
            $currentHost = strtolower(preg_replace("/:\d+$/", "", $currentHost) ?? "");
            $normalizedHost = strtolower($host);

            // If endpoint targets the same public host, prefer local origin to avoid Cloudflare challenge.
            if ($normalizedHost !== "" && $currentHost !== "" && $normalizedHost === $currentHost && strpos($path, "/modules/addons/domainsreseller/api.php") !== false) {
                $rawEndpoint = "http://127.0.0.1" . $path;
                if (!empty($parts["query"])) {
                    $rawEndpoint .= "?" . $parts["query"];
                }
                $parts = parse_url($rawEndpoint);
            }
        }

        if (is_array($parts) && ($parts["host"] ?? "") === "localhost" && (int) ($parts["port"] ?? 0) === 8080) {
            $rawEndpoint = (string) ($parts["scheme"] ?? "http") . "://localhost";
            if (!empty($parts["path"])) {
                $rawEndpoint .= $parts["path"];
            }
            if (!empty($parts["query"])) {
                $rawEndpoint .= "?" . $parts["query"];
            }
        }

        return rtrim($rawEndpoint, "/");
    }

    private static function buildUrl(string $endpoint, string $path): string
    {
        $path = "/" . ltrim($path, "/");
        $pathUrl = parse_url($path);
        $routePath = (string) ($pathUrl["path"] ?? "/");
        $routeQuery = [];
        if (!empty($pathUrl["query"])) {
            parse_str((string) $pathUrl["query"], $routeQuery);
        }

        $endpointPath = (string) (parse_url($endpoint, PHP_URL_PATH) ?? "");
        $usesPhpEntry = stripos($endpointPath, ".php") !== false;

        if ($usesPhpEntry) {
            $separator = strpos($endpoint, "?") === false ? "?" : "&";
            $url = $endpoint . $separator . "endpoint=" . rawurlencode($routePath);
            if (!empty($routeQuery)) {
                $url .= "&" . http_build_query($routeQuery);
            }
            return $url;
        }

        return rtrim($endpoint, "/") . "/" . ltrim($path, "/");
    }

    /**
     * Load saved registrar module settings for admin widgets / diagnostics.
     */
    public static function getRegistrarParams(string $registrar = "hostopy"): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }

        $params = ["systemurl" => ""];
        if (class_exists(\WHMCS\Config\Setting::class)) {
            $params["systemurl"] = (string) \WHMCS\Config\Setting::getValue("SystemURL");
        }

        try {
            $rows = \WHMCS\Database\Capsule::table("tblregistrars")
                ->where("registrar", $registrar)
                ->get(["setting", "value"]);
        } catch (\Throwable $e) {
            return $params;
        }

        foreach ($rows as $row) {
            $key = (string) ($row->setting ?? "");
            if ($key === "") {
                continue;
            }
            $raw = (string) ($row->value ?? "");
            try {
                $params[$key] = function_exists("decrypt") ? (string) decrypt($raw) : $raw;
            } catch (\Throwable $e) {
                $params[$key] = $raw;
            }
        }

        return $params;
    }

    /**
     * Credits + connectivity snapshot for WHMCS admin home widget.
     *
     * @return array{configured:bool,balance:string,connectivity:string,connected:bool,error:string}
     */
    public static function getWidgetData(?array $params = null): array
    {
        $params = $params ?? self::getRegistrarParams();
        $apiKey = trim((string) ($params["apiKey"] ?? ""));
        $endpoint = trim((string) ($params["apiEndpoint"] ?? ""));

        if ($apiKey === "" || $endpoint === "") {
            return [
                "configured" => false,
                "balance" => "N/A",
                "connectivity" => "Not Configured",
                "connected" => false,
                "error" => "Configure apiEndpoint and apiKey in Setup → Domain Registrars → HostOpy Register.",
            ];
        }

        $version = self::call($params, "GET", "/version");
        $connected = !isset($version["error"]);

        if (!$connected) {
            return [
                "configured" => true,
                "balance" => "N/A",
                "connectivity" => "Disconnected",
                "connected" => false,
                "error" => (string) ($version["error"] ?? "Unable to reach HostOpy API."),
            ];
        }

        $balance = "N/A";
        $credits = self::call($params, "GET", "/credits");
        if (isset($credits["balance"])) {
            $balance = self::formatBalance((float) $credits["balance"]);
        } elseif (!empty($credits["error"])) {
            return [
                "configured" => true,
                "balance" => "N/A",
                "connectivity" => "Connected",
                "connected" => true,
                "error" => (string) $credits["error"],
            ];
        }

        return [
            "configured" => true,
            "balance" => $balance,
            "connectivity" => "Connected",
            "connected" => true,
            "error" => "",
        ];
    }

    private static function formatBalance(float $amount): string
    {
        try {
            $currency = \WHMCS\Database\Capsule::table("tblcurrencies")->where("default", 1)->first();
            if ($currency) {
                $prefix = (string) ($currency->prefix ?? "");
                $suffix = (string) ($currency->suffix ?? "");
                return $prefix . number_format($amount, 2, ".", "") . $suffix;
            }
        } catch (\Throwable $e) {
        }

        return "₹" . number_format($amount, 2, ".", "");
    }

}

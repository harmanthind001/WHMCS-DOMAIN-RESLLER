<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList;
use WHMCS\Database\Capsule;

require_once __DIR__ . "/lib/Request.php";
hostopy_sanitizeDomainRequest();

function hostopy_MetaData(): array
{
    return [
        "DisplayName" => "HostOpy Register",
        "APIVersion" => "2.1",
    ];
}

function hostopy_getConfigArray(): array
{
    return [
        "apiEndpoint" => [
            "Type" => "text",
            "Size" => "60",
            "Description" => "Provider API endpoint",
        ],
        "apiKey" => [
            "Type" => "password",
            "Size" => "60",
            "Description" => "Reseller API key",
        ],
        "originEndpoint" => [
            "Type" => "text",
            "Size" => "60",
            "Description" => "Optional direct origin endpoint (e.g. https://hostopy.com/modules/addons/domainsreseller/api.php) to bypass Cloudflare",
        ],
    ];
}

function hostopy_RegisterDomain(array $params): array
{
    return hostopy_call($params, "POST", "/domain/register", [
        "domain" => $params["sld"] . "." . $params["tld"],
        "years" => (int) ($params["regperiod"] ?? 1),
        "contacts" => hostopy_extractContacts($params),
        "nameservers" => hostopy_extractNameservers($params),
        "addons" => [
            "idProtection" => !empty($params["idprotection"]),
            "dnsManagement" => !empty($params["dnsmanagement"]),
            "emailForwarding" => !empty($params["emailforwarding"]),
        ],
        "eppCode" => (string) ($params["eppcode"] ?? ""),
    ]);
}

function hostopy_TransferDomain(array $params): array
{
    return hostopy_call($params, "POST", "/domain/transfer", [
        "domain" => $params["sld"] . "." . $params["tld"],
        "years" => (int) ($params["regperiod"] ?? 1),
        "eppCode" => $params["eppcode"] ?? "",
        "contacts" => hostopy_extractContacts($params),
        "nameservers" => hostopy_extractNameservers($params),
    ]);
}

function hostopy_RenewDomain(array $params): array
{
    return hostopy_call($params, "POST", "/domain/renew", [
        "domain" => $params["sld"] . "." . $params["tld"],
        "years" => (int) ($params["regperiod"] ?? 1),
    ]);
}

function hostopy_GetNameservers(array $params): array
{
    return hostopy_call($params, "GET", "/nameservers?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_SaveNameservers(array $params): array
{
    return hostopy_call($params, "PUT", "/nameservers", [
        "domain" => $params["domainname"] ?? "",
        "nameservers" => [
            $params["ns1"] ?? "",
            $params["ns2"] ?? "",
            $params["ns3"] ?? "",
            $params["ns4"] ?? "",
            $params["ns5"] ?? "",
        ],
    ]);
}

function hostopy_GetRegistrarLock(array $params): array
{
    return hostopy_call($params, "GET", "/lock?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_SaveRegistrarLock(array $params): array
{
    return hostopy_call($params, "PUT", "/lock", [
        "domain" => $params["domainname"] ?? "",
        "lock" => (string) ($params["lockenabled"] ?? "") === "locked",
    ]);
}

function hostopy_GetContactDetails(array $params): array
{
    return hostopy_call($params, "GET", "/contact?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_SaveContactDetails(array $params): array
{
    return hostopy_call($params, "PUT", "/contact", [
        "domain" => $params["domainname"] ?? "",
        "contact" => $params["contactdetails"] ?? [],
    ]);
}

function hostopy_GetEPPCode(array $params): array
{
    return hostopy_call($params, "GET", "/epp-code?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_GetDNS(array $params): array
{
    return hostopy_call($params, "GET", "/dns?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_SaveDNS(array $params): array
{
    return hostopy_call($params, "PUT", "/dns", [
        "domain" => $params["domainname"] ?? "",
        "records" => $params["dnsrecords"] ?? [],
    ]);
}

function hostopy_GetEmailForwarding(array $params): array
{
    return hostopy_call($params, "GET", "/email-forwarding?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_SaveEmailForwarding(array $params): array
{
    return hostopy_call($params, "PUT", "/email-forwarding", [
        "domain" => $params["domainname"] ?? "",
        "forwarders" => $params["emailforwarders"] ?? [],
    ]);
}

function hostopy_RegisterNameserver(array $params): array
{
    return hostopy_call($params, "POST", "/nameservers", [
        "domain" => $params["domainname"] ?? "",
        "name" => $params["nameserver"] ?? "",
        "ip" => $params["ipaddress"] ?? "",
    ]);
}

function hostopy_ModifyNameserver(array $params): array
{
    return hostopy_call($params, "PUT", "/nameservers", [
        "domain" => $params["domainname"] ?? "",
        "name" => $params["nameserver"] ?? "",
        "newName" => $params["newnameserver"] ?? "",
        "ip" => $params["newipaddress"] ?? "",
    ]);
}

function hostopy_DeleteNameserver(array $params): array
{
    return hostopy_call($params, "DELETE", "/nameservers", [
        "domain" => $params["domainname"] ?? "",
        "name" => $params["nameserver"] ?? "",
    ]);
}

function hostopy_IDProtectToggle(array $params): array
{
    return hostopy_call($params, "PUT", "/id-protection", [
        "domain" => $params["domainname"] ?? "",
        "enabled" => (bool) ($params["protectenable"] ?? false),
    ]);
}

function hostopy_Sync(array $params): array
{
    return hostopy_call($params, "GET", "/domain/sync?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_TransferSync(array $params): array
{
    return hostopy_call($params, "GET", "/domain/sync?domain=" . urlencode($params["domainname"] ?? ""));
}

function hostopy_GetTldPricing(array $params)
{
    [$preferredCurrencyId, $preferredCurrencyCode] = hostopy_getPreferredCurrency($params);
    $path = "/tlds/pricing";
    if ($preferredCurrencyId > 0) {
        $path .= "?currency_id=" . $preferredCurrencyId;
    }

    $response = hostopy_call($params, "GET", $path);
    if (isset($response["error"])) {
        return ["error" => (string) $response["error"]];
    }

    $items = $response["items"] ?? [];
    if (!is_array($items)) {
        return ["error" => "Invalid pricing response"];
    }

    $map = [];
    foreach ($items as $row) {
        if (!is_array($row) || empty($row["extension"]) || empty($row["action"]) || empty($row["years"])) {
            continue;
        }
        $ext = (string) $row["extension"];
        $year = (int) $row["years"];
        $action = (string) $row["action"];
        $price = (float) ($row["price"] ?? 0);
        if (!isset($map[$ext])) {
            $map[$ext] = [];
        }
        if (!isset($map[$ext][$year])) {
            $map[$ext][$year] = ["register" => 0.0, "renew" => 0.0, "transfer" => 0.0];
        }
        if (isset($map[$ext][$year][$action])) {
            $map[$ext][$year][$action] = $price;
        }
    }

    $currency = $preferredCurrencyCode;
    $results = new ResultsList();

    foreach ($map as $extension => $yearsData) {
        ksort($yearsData);
        $usableYears = [];
        foreach ($yearsData as $year => $vals) {
            $register = (float) ($vals["register"] ?? 0.0);
            $renew = (float) ($vals["renew"] ?? 0.0);
            $transfer = (float) ($vals["transfer"] ?? 0.0);
            if ($register > 0 || $renew > 0 || $transfer > 0) {
                $usableYears[] = (int) $year;
            }
        }
        if ($usableYears === []) {
            continue;
        }
        sort($usableYears);
        $firstYear = (int) ($usableYears[0] ?? 1);
        $lastYear = (int) ($usableYears[count($usableYears) - 1] ?? $firstYear);
        $base = $yearsData[$firstYear] ?? ["register" => 0.0, "renew" => 0.0, "transfer" => 0.0];

        $item = (new ImportItem())
            ->setExtension((string) $extension)
            ->setMinYears(max(1, $firstYear))
            ->setMaxYears(max(1, $lastYear))
            ->setRegisterPrice((float) ($base["register"] ?? 0.0))
            ->setRenewPrice((float) ($base["renew"] ?? 0.0))
            ->setTransferPrice((float) ($base["transfer"] ?? 0.0))
            ->setCurrency($currency)
            ->setEppRequired(true);
        $results[] = $item;
    }

    return $results;
}

function hostopy_getPreferredCurrency(array $params): array
{
    $code = strtoupper((string) ($params["currencycode"] ?? ""));
    if ($code !== "") {
        $row = Capsule::table("tblcurrencies")
            ->whereRaw("UPPER(code) = ?", [$code])
            ->first(["id", "code"]);
        if ($row) {
            return [(int) $row->id, strtoupper((string) $row->code)];
        }
    }

    $default = Capsule::table("tblcurrencies")
        ->where("default", 1)
        ->first(["id", "code"]);
    if ($default) {
        return [(int) $default->id, strtoupper((string) $default->code)];
    }

    return [0, $code !== "" ? $code : "USD"];
}

function hostopy_call(array $params, string $method, string $path, array $body = []): array
{
    return \WHMCS\Module\Registrar\Hostopy\Request::call(
        $params,
        $method,
        $path,
        $body
    );
}

function hostopy_extractContacts(array $params): array
{
    $contacts = [];
    $contactDetails = $params["contactdetails"] ?? [];
    if (is_array($contactDetails) && !empty($contactDetails)) {
        foreach (["Registrant", "Admin", "Tech", "Billing"] as $type) {
            if (!empty($contactDetails[$type]) && is_array($contactDetails[$type])) {
                $contacts[strtolower($type)] = $contactDetails[$type];
            }
        }
    }

    if (empty($contacts["registrant"])) {
        $contacts["registrant"] = hostopy_extractFallbackRegistrant($params);
    }

    foreach (["admin", "tech", "billing"] as $type) {
        if (empty($contacts[$type])) {
            $contacts[$type] = $contacts["registrant"];
        }
    }

    return $contacts;
}

function hostopy_extractFallbackRegistrant(array $params): array
{
    return array_filter([
        "firstName" => (string) ($params["firstname"] ?? ""),
        "lastName" => (string) ($params["lastname"] ?? ""),
        "companyName" => (string) ($params["companyname"] ?? ""),
        "email" => (string) ($params["email"] ?? ""),
        "address1" => (string) ($params["address1"] ?? ""),
        "address2" => (string) ($params["address2"] ?? ""),
        "city" => (string) ($params["city"] ?? ""),
        "state" => (string) ($params["state"] ?? ""),
        "postcode" => (string) ($params["postcode"] ?? ""),
        "country" => (string) ($params["country"] ?? ""),
        "phone" => (string) ($params["phonenumber"] ?? ""),
    ], static function ($value): bool {
        return $value !== "";
    });
}

function hostopy_extractNameservers(array $params): array
{
    $fromKeys = [];
    foreach (["ns1", "ns2", "ns3", "ns4", "ns5"] as $key) {
        $value = trim((string) ($params[$key] ?? ""));
        if ($value !== "") {
            $fromKeys[] = $value;
        }
    }
    if ($fromKeys !== []) {
        return array_values(array_unique($fromKeys));
    }

    $fromArray = $params["nameserver"] ?? $params["ns"] ?? [];
    if (is_array($fromArray)) {
        $clean = [];
        foreach ($fromArray as $value) {
            $value = trim((string) $value);
            if ($value !== "") {
                $clean[] = $value;
            }
        }
        if ($clean !== []) {
            return array_values(array_unique($clean));
        }
    }

    return [];
}

function hostopy_sanitizeDomainRequest(): void
{
    foreach (["domain", "domainname"] as $key) {
        foreach (["_GET", "_POST", "_REQUEST"] as $bagName) {
            if (!isset($GLOBALS[$bagName][$key])) {
                continue;
            }
            $value = $GLOBALS[$bagName][$key];
            if (is_array($value)) {
                $value = hostopy_firstScalar($value);
            }
            $value = trim((string) $value);
            if ($value === "") {
                unset($GLOBALS[$bagName][$key]);
            } else {
                $GLOBALS[$bagName][$key] = $value;
            }
        }
    }
}

function hostopy_firstScalar($value): string
{
    if (!is_array($value)) {
        return trim((string) $value);
    }
    foreach ($value as $item) {
        $v = hostopy_firstScalar($item);
        if ($v !== "") {
            return $v;
        }
    }
    return "";
}

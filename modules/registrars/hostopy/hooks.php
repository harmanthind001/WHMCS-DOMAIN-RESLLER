<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Registrar\Hostopy\Request;
use WHMCS\View\Menu\Item as MenuItem;

add_hook("ClientAreaPrimarySidebar", 1, function (MenuItem $primarySidebar): void {
    $domainDetails = $primarySidebar->getChild("Domain Details Management");
    if (!$domainDetails) {
        return;
    }

    if (!$domainDetails->getChild("Get EPP Code")) {
        $domainDetails->addChild("Get EPP Code", [
            "label" => "Get EPP Code",
            "uri" => "clientarea.php?action=domaingetepp&id=" . (int) ($_REQUEST["id"] ?? 0),
            "order" => 95,
        ]);
    }
});

add_hook("AdminHomeWidgets", 1, function () {
    return new HostopyAdminWidget();
});

class HostopyAdminWidget extends \WHMCS\Module\AbstractWidget
{
    protected $title = "HostOpy Register Reseller Status";
    protected $description = "HostOpy API connection and available reseller funds";
    protected $weight = 90;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 300;
    protected $requiredPermission = "";

    public function getData()
    {
        return Request::getWidgetData();
    }

    public function generateOutput($data)
    {
        $balance = htmlspecialchars((string) ($data["balance"] ?? "N/A"), ENT_QUOTES, "UTF-8");
        $connected = !empty($data["connected"]);
        $configured = !empty($data["configured"]);
        $error = trim((string) ($data["error"] ?? ""));

        if (!$configured) {
            $connectHtml = "<span style=\"color:#856404;font-weight:bold;\"><span style=\"display:inline-block;width:10px;height:10px;border-radius:50%;background:#ffc107;margin-right:6px;\"></span>Not Configured</span>";
        } elseif ($connected) {
            $connectHtml = "<span style=\"color:#48a84d;font-weight:bold;\"><span style=\"display:inline-block;width:10px;height:10px;border-radius:50%;background:#28a745;margin-right:6px;\"></span>Connected</span>";
        } else {
            $connectHtml = "<span style=\"color:#dc3545;font-weight:bold;\"><span style=\"display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;margin-right:6px;\"></span>Disconnected</span>";
        }

        $balanceColor = $connected ? "#48a84d" : "#666";
        $errorHtml = "";
        if ($error !== "" && (!$connected || $balance === "N/A")) {
            $errorHtml = "<div style=\"margin-top:10px;font-size:12px;color:#856404;\">"
                . htmlspecialchars($error, ENT_QUOTES, "UTF-8")
                . "</div>";
        }

        return <<<HTML
<div style="padding:16px 8px 12px 8px;font-size:15px;line-height:1.7;min-width:180px;">
    <div style="margin-bottom:10px;">
        <span style="font-weight:500;">HostOpy Register Credits:</span>
        <span style="font-size:1.12em;color:{$balanceColor};font-weight:bold;">{$balance}</span>
    </div>
    <div>
        <span style="font-weight:500;">API Connection:</span>
        {$connectHtml}
    </div>
    {$errorHtml}
</div>
HTML;
    }
}

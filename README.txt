WHMCS Registrar Module
======================

Module Name : HostOpy Register
Developer   : HostOpy
Website     : https://hostopy.com/
Support     : https://hostopy.com/
Directory   : modules/registrars/hostopy
Main File   : hostopy.php

Overview:
---------
HostOpy Registrar Module connects WHMCS with the HostOpy Domain Reseller API to automate domain registrations, transfers, renewals, DNS management, and more.

Key Features:
-------------
- Automated Domain Registration & Transfers
- Automated Domain Renewals
- Nameserver Management (View & Update)
- Custom / Child Nameserver Registration, Modification & Deletion
- Registrar Lock (Theft Protection) Management
- WHOIS / Contact Details Management (Registrant, Admin, Tech, Billing)
- EPP / Auth Code Retrieval
- DNS Records Management (DNS Zone Editing)
- Email Forwarding Configuration
- ID Protection / WHOIS Privacy Toggle
- Real-time Domain Expiry & Status Sync (Cron / Automation)
- Transfer Status Synchronization
- TLD Pricing Import & Sync (GetTldPricing)

Installation & Setup:
---------------------
1) Upload and extract this zip file into your WHMCS root directory.
   (The files will be placed into modules/registrars/hostopy/)
2) Log in to your WHMCS Admin Area.
3) Navigate to: System Settings -> Domain Registrars (or Setup -> Products/Services -> Domain Registrars).
4) Find 'HostOpy Register' and click Activate.
5) Enter your API credentials:
   - API Endpoint: (Default: /modules/addons/domainsreseller/api.php or HostOpy API URL)
   - API Key: Your HostOpy Reseller API Key
6) Save Changes.

Website & Documentation:
------------------------
For account registration, reseller API keys, and support:
Visit: https://hostopy.com/


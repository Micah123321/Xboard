# GeoSite service domain snapshot

Source: https://github.com/v2fly/domain-list-community (MIT; see LICENSE.geosite).
The 124 selected service groups and their includes were compiled into 13753 exact/suffix rules.
The Beijing calendar-day review for October 17-18 added academic repositories,
creator/VR/digital-content services, application distribution and exact game CDN hosts.
See docs/access-traffic-calendar-review-2026-10-17-18.md for per-day coverage and
the explicit incomplete-day boundary for October 18.
The October 18 three-hour review added game distribution, media, certificate, productivity
and education service groups using observed unknown targets. Reviewed positive/negative
examples and confidence evidence are in reviewed-evidence.json; see
docs/access-traffic-unknown-review-2026-10-18.md for the measured coverage and limits.
Coverage includes named crypto exchanges/wallets, finance, development/network tools,
communications, social platforms, games, shopping, productivity and education category lists.
AI and netdisk groups are included. Shared advertising/telemetry domains are omitted.
telegram-cidr.txt contains the 14 published ranges from https://core.telegram.org/resources/cidr.txt
for dedicated Telegram service inference; these are not generic cloud/ISP ownership rules.
Source file SHA256 values are embedded in geosite.json. Regex/plain and attribute-filtered
includes are skipped rather than widened. The release contains a usable offline snapshot.

The official source archive used for this build was downloaded from
https://codeload.github.com/v2fly/domain-list-community/zip/refs/heads/master
SHA256: 33af033711bb4270ac890ad971098d537a25032bbe5f3fa0437defbe6ecbcc7c

Refresh with: php artisan access-traffic:sync-domains
Inspect with: php artisan access-traffic:sync-domains --status
Reclassify stored records with: php artisan access-traffic:reclassify
The updater writes storage/app/access-traffic/geosite.json atomically and preserves this snapshot.
See docs/access-traffic-query-optimization.md for behavior, limits and validation.

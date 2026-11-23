# Enkel kursanmälan med Swish

Ett WordPress-plugin för kurser där varje kurs sköts separat: egen anmälningslista, eget välkomstmail, utskick och maillista.

## Funktioner

- Kurser med rubrik, formaterad beskrivning, pris och flera kurstillfällen med max antal deltagare
- Kategorier med egna betalregler (t.ex. 100 % direkt eller 20 % i föranmälningsavgift)
- Kursansvarig (WordPress-användare) som får mail vid varje anmälan
- Eget välkomstmail per kurs med kortkoder, och en standardmall
- Kopplade informationssidor (avanmälan, betalning m.m.) och "I samarbete med" med loggor
- Valbart krav på personnummer per deltagare, sparat krypterat
- Per kurs i admin: anmälningslista, CSV-export, avbokning, utskick och maillista med avregistreringslänk
- Swish-länk och QR-kod på tacksidan
- Shortcodes: `[enkel_kurslista]` och `[enkel_kursdatumlista]`, båda med `kategori="slug"`

## Kodstruktur

| Fil | Ansvar |
| --- | --- |
| `includes/class-eka-db.php` | Tabeller och uppgradering |
| `includes/class-eka-core.php` | Kurstillfällen, platser, betalregler, personnummer, kryptering |
| `includes/class-eka-courses.php` | Kurser, kategorier och redigeringsrutor |
| `includes/class-eka-mailer.php` | Välkomstmail, avisering och kortkoder |
| `includes/class-eka-admin.php` | Översikt, kurssida, utskick, maillista, inställningar |
| `includes/class-eka-frontend.php` | Kurssida, anmälan, tacksida, avregistrering |
| `includes/class-eka-updater.php` | Uppdateringar från GitHub-releaser |

## Installation

Ladda ner ZIP-filen från den senaste releasen och installera den via **Tillägg → Lägg till nytt → Ladda upp tillägg** i WordPress.

## Uppdateringar

Från version 2.0.0 hämtar pluginet själv nya versioner från repositoryts GitHub-releaser. En ny version syns under **Tillägg** som vanligt och kan installeras med **Uppdatera nu**. Klicka **Aktivera automatiska uppdateringar** på pluginets rad för att låta WordPress installera nya versioner själv. Länken **Sök efter uppdatering** på samma rad tvingar fram en kontroll direkt (annars kontrolleras GitHub var sjätte timme).

Webbplatser som har 1.1.0 eller äldre saknar uppdateraren och måste uppdateras manuellt en gång.

## Släppa en ny version

1. Höj versionsnumret på tre ställen: `Version:` och `EKA_VERSION` i `enkel-kursanmalan.php`, samt `Stable tag:` i `readme.txt`.
2. Committa och slå ihop till `main`.
3. Tagga och pusha:

   ```
   git tag v2.1.0
   git push origin v2.1.0
   ```

GitHub-flödet `.github/workflows/release.yml` kontrollerar att versionsnumren stämmer med taggen, kontrollerar PHP-syntaxen, bygger `enkel-kursanmalan-v2.1.0.zip` och skapar releasen. Webbplatserna hittar den sedan själva.

## Support

Fel och önskemål rapporteras via repositoryts GitHub Issues.

## Licens

GPL-2.0-or-later. Copyright Websmedjan.

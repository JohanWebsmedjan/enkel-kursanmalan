=== Enkel kursanmälan med Swish ===
Contributors: JohanWebsmedjan
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enkelt anmälningssystem för sammanhållna kurser med flera inkluderade datum och procentuell Swish-handpenning.

Utvecklas och underhålls av Websmedjan: https://github.com/JohanWebsmedjan

== Installation ==
1. Ladda upp ZIP-filen under Tillägg > Lägg till nytt > Ladda upp tillägg.
2. Aktivera tillägget.
3. Gå till Kurser > Inställningar och fyll i Swish-nummer och handpenning i procent.
4. Skapa kurser under Kurser > Lägg till kurs.
5. Lägg shortcoden [enkel_kurslista] på sidan där kursutbudet ska visas.
6. Använd [enkel_kursdatumlista] för en datumlista med kalenderikon, startdatum och starttid.

Varje kurs har ett enda bokningsbart startdatum, starttid, valfri sluttid och ett maxantal deltagare. Ytterligare datum kan läggas till som delar av samma kurs. En anmälan gäller automatiskt hela kursserien och alla datum som ingår.

Den som gör anmälan anger obligatoriskt namn, e-post, telefon, adress, postnummer och ort. Vid anmälan av flera deltagare krävs namn, e-post och telefon för varje ytterligare person.

== Betalningsflöde ==
Efter anmälan visas Swish-nummer, uträknad handpenning och en unik referens. Administratören markerar betalningen som mottagen under Kurser > Anmälningar. Automatisk verifiering ingår inte i denna version.

== E-post ==
Välkomstmail skickas automatiskt vid anmälan. När administratören avbokar en anmälan skickas ett automatiskt avbokningsmail. Mallarna redigeras under Kurser > Inställningar. Under Kurser > Kursmail kan administratören skicka ett separat mail till alla aktiva anmälningar på en kurs.

== Changelog ==
= 1.2.0 =
* Redigerbara mallar för välkomst- och avbokningsmail.
* Avbokning av anmälan frigör platser och skickar avbokningsmail.
* Manuella individuella utskick per kurs.
* En kurs har ett startdatum och kan innehålla flera efterföljande datum i samma anmälan.
* Separata obligatoriska kontaktuppgifter för samtliga deltagare samt adressuppgifter för den som anmäler.
* Alla kursdatum visas i formatet YYYY-MM-DD.

= 1.1.0 =
* Kurstillfällen med datum, tider och max antal deltagare.

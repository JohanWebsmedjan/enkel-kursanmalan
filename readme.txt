=== Enkel kursanmälan med Swish ===
Contributors: JohanWebsmedjan
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enkelt anmälningssystem för kurser med kurstillfällen och procentuell Swish-handpenning.

Utvecklas och underhålls av Websmedjan: https://github.com/JohanWebsmedjan

== Installation ==
1. Ladda upp ZIP-filen under Tillägg > Lägg till nytt > Ladda upp tillägg.
2. Aktivera tillägget.
3. Gå till Kurser > Inställningar och fyll i Swish-nummer och handpenning i procent.
4. Skapa kurser under Kurser > Lägg till kurs.
5. Lägg shortcoden [enkel_kurslista] på sidan där kursutbudet ska visas.
6. Använd [enkel_kursdatumlista] för en datumlista med kalenderikon, startdatum och starttid.

Varje kurstillfälle har startdatum, starttid, valfri sluttid och max antal deltagare. Tillfällen kan tas bort i kursredigeringen. Fullbokade tillfällen kan inte väljas.

== Betalningsflöde ==
Efter anmälan visas Swish-nummer, uträknad handpenning och en unik referens. Administratören markerar betalningen som mottagen under Kurser > Anmälningar. Automatisk verifiering ingår inte i denna version.

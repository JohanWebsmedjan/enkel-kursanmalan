=== Enkel kursanmälan med Swish ===
Contributors: JohanWebsmedjan
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Anmälningssystem för kurser med egna anmälningslistor, kategorier med betalregler, välkomstmail, utskick och Swish-betalning.

Utvecklas och underhålls av Websmedjan: https://github.com/JohanWebsmedjan

== Installation ==
1. Ladda upp ZIP-filen under Tillägg > Lägg till nytt > Ladda upp tillägg.
2. Aktivera tillägget.
3. Gå till Kurser > Inställningar och fyll i Swish-nummer, standard för föranmälningsavgift och standardmall för välkomstmail.
4. Skapa kategorier under Kurser > Kategorier och ange föranmälningsavgift i procent (100 = hela beloppet direkt).
5. Skapa kurser under Kurser > Lägg till kurs.
6. Lägg shortcoden [enkel_kurslista] på sidan där kursutbudet ska visas, eller [enkel_kursdatumlista] för en datumlista. Båda kan begränsas med kategori="slug".

== Visningssida ==
Kurserna kan visas med en egen designad sida i stället för temats mall:
1. Skapa en vanlig sida och lägg [enkel_kurs] där kursen ska visas.
2. Välj sidan som visningssida under Kurser > Inställningar, eller på en kategori för att bara gälla den kategorins kurser.
När en kurs öppnas visas sidan, med sidans mall, och [enkel_kurs] byts mot kursens beskrivning, samarbetspartner, informationssidor och anmälningsformulär. Kurserna behåller sina egna adresser, så länkar i mail och kurslistor fungerar som tidigare.
Delarna kan placeras var för sig: [enkel_kurs del="titel,bild,beskrivning,utdrag,pris,tillfallen,partners,information,formular"]. Med id="123" visas en viss kurs på vilken sida som helst.
Sidan måste vara byggd med WordPress blockredigerare eller den klassiska redigeraren. Sidbyggare som Elementor sparar sin layout på ett annat sätt och fungerar inte som visningssida.

== Kursens inställningar ==
* Rubrik och beskrivning skrivs i WordPress vanliga redigerare.
* Pris per deltagare, kategori (styr betalregeln) och kursansvarig.
* Om personnummer krävs. Då anges namn och personnummer för varje deltagare. Personnummer sparas krypterat.
* Kurstillfällen med datum, tider och max antal deltagare.
* Eget välkomstmail med kortkoder som {namn}, {tillfalle}, {att_betala} och {referens}.
* Informationssidor, t.ex. avanmälningsvillkor och betalningsinformation.
* Samarbetspartner med logga och länk ("I samarbete med").

== Per kurs i administrationen ==
Under Kurser > Anmälningar väljer man en kurs och får:
* Anmälningslista med sammanställning per tillfälle, filter, CSV-export, betald/avbokad och borttagning.
* Utskick till anmälda, till kursens maillista eller till dem som vill ha information om kommande kurser.
* Kursens maillista med export. Alla anmälda läggs till; samtycke till information om kommande kurser är frivilligt.

== Nyhetsbrev ==
Om The Newsletter Plugin är installerat väljer man en lista under Kurser > Inställningar. Den som kryssar i "Jag vill gärna få information om kommande kurser" läggs då automatiskt i listan som bekräftad prenumerant. Befintliga prenumeranter med samtycke kan föras över med en knapp på samma sida.

== Betalningsflöde ==
Efter anmälan visas belopp, Swish-nummer, unik referens, en knapp som öppnar Swish och en QR-kod. Betalningen markeras som mottagen i kursens anmälningslista. Automatisk verifiering ingår inte.

=== RiskCUBE ===
Contributors: NxtLvl Development
Tags: woocommerce, invoice, riskcube
Requires at least: 5.3
Tested up to: 6.3
Requires PHP: 7.4
Stable Tag: 2.4.6
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Mit der RiskCUBE haben Sie die Sicherheit, dass Kauf auf Rechnungen möglich ist,
sofern der Käufer eine gute Bonität aufweist. Das bringt mehr Umsatz und weniger
Ärger mit säumigen Zahlern.

== Description ==
Scroll down for English Version

= Bedürfnis =
Die Zahlart «Rechnung» soll nur bonitätsgeprüften Kunden
mit kalkulierbarem Risiko offeriert werden. Ein intelligentes
Regelwerk soll Missbrauch erkennen und ausschliessen

= Hintergrund =
In der Schweiz bleibt «Rechnung» weiterhin die beliebteste
Zahlart im Online-Handel. Bietet ein Händler diese Zahlart
nicht an, riskiert er einen hohen Anteil von Kaufabbrüchen.
Die Zahlart ist aber mit vielen Risiken behaftet. RiskCUBE
hilft dem Händler, die Rechnungsoption mit kalkulierbarem
Risiko anzubieten.

= Leistungsumfang =
Nach Eingabe der Adressangaben und vor der Anzeige der
Zahlungsoptionen werden die Daten an RiskCUBE übergeben.
RiskCUBE prüft die Bonität und das Verhalten des
Shop-Bestellers. Der Shop-Betreiber kann die Zahlarten
bei fehlender Bonität, auffälligem Verhalten, ungültiger
Anschrift, oder bei Überschreiten einer bestimmten Kreditlimite
einschränken. Je nach Risikobereitschaft und
Marge kann die Anzeige der Rechnungsoption eingeschränkt
oder ausgeweitet werden. Weitere Informationen
dazu weiter unten.

= Unterstützte Länder =
- Schweiz
- Liechtenstein für Firmen

= Steuerungsmöglichkeiten =
- Matchtoleranz (low, regular, high).
- Mindest-Bestellwert pro Transaktion.
- Individuelle Kreditlimite für Firmen- und Privat-Kunden
pro Tag und Periode (z.B. 30 Tage) je nach Bonität:
    - gut (grün)
    - unsicher (gelb)
    - schlecht (rot, Default CHF 0)
    - resp. Person unbekannt
- Maximale Kreditlimite bei Systemausfall.
- Abweichende Rechnungs- und Lieferadresse erlauben/sperren.
- Keine Rechnung, wenn die Person unbekannt ist.
- Möglichkeit, einzelne Kunden mit Whitelist positiv
oder mit Blacklist negativ übersteuern.

= Betrugserkennung =
- Auffälliges Verhalten im Shop schliesst den Rechnungskauf aus.
- Rechnung nur bei postalisch gültiger Anschrift und bekannte
Betrugsanschriften sperren.

Wir empfehlen zusätzlich IP analytische Fraud AddOns zu verwenden.

= RiskCUBE API =
Es steht eine moderne Rest-Schnittstelle zur Verfügung.
Creditreform stellt nach Unterzeichnung einer
Vertraulichkeitsvereinbarung die Dokumentation und
Zugangsdaten gerne zur Verfügung.

= Individualanbindung =
Für eine individuelle Anbindung Ihres Shops ohne RiskCUBE
erhalten Sie nach Unterzeichnung der Vertraulichkeitsvereinbarung
die Schnittstellendokumentation und bei Bedarf Zugangsdaten.

[Der offizielle Flyer](https://www.creditreform.ch/fileadmin/user_upload/central_files/_documents/01_loesungen/14_systemintegration/_riskcube/04_Flyer_RiskCUBE_WooCommerce_DE.pdf)

= Need =
The "Invoice" payment method should only be offered to
customers with a calculable risk. Intelligent
rules should detect and exclude misuse

= Background =
In Switzerland, "invoice" continues to be the most popular
payment method in online commerce. If a merchant does not offer this
payment method, he risks a high proportion of abandoned purchases.
However, the payment method is fraught with many risks. RiskCUBE
helps the merchant to offer the invoice option with a calculable
risk.

= Scope of services =
After entering the address information and before the display of the
payment options, the data is transferred to RiskCUBE.
RiskCUBE checks the creditworthiness and the behavior of the
customer. The store operator can select the payment methods
in case of missing creditworthiness, conspicuous behavior, invalid
address, or if a certain credit limit is exceeded.
limit. Depending on the risk and margin, the display
of the billing option can be restricted or extended.
Further information on this further down.

= Supported countries =
- Switzerland
- Liechtenstein for companies

= Control options =
- Match tolerance (low, regular, high).
- Minimum order value per transaction.
- Individual credit limits for corporate and private customers
per day and period (e.g. 30 days) depending on creditworthiness:
    - good (green)
    - unsecure (yellow)
    - bad (red, default CHF 0)
    - person unknown
- Maximum credit limit in case of system failure.
- Allow/block different billing and shipping address.
- No invoice if person is unknown.
- Possibility to positively override individual customers with whitelist
or negatively override with blacklist.

= Fraud detection =
- Conspicuous behavior in the store excludes purchase with invoice.
- Invoice only with postal valid address and blocking known fraud addresses.

We recommend to use additional IP analytical Fraud AddOns.

= RiskCUBE API =
A modern rest interface is available.
Creditreform provides after signing a
documentation and access data after signing a confidentiality agreement.
access data gladly available.

= Individual connection =
For an individual connection of your store without RiskCUBE
you will receive after signing the confidentiality agreement
the interface documentation and if necessary access data.

== Frequently Asked Questions ==
= How can I get access to this Plugin? =
Visit [creditreform.ch](https://www.creditreform.ch/en/solutions/system-integration/connections-for-webshops) for more information.

== Changelog ==
= 2.4.12 =
**Features**
- Support for WooCommerce Classic and WooCommerce Blocks checkout
- Transaction History in Plugin settings
- Improved frontend Javascript
- Improved plugin settings
- Adjusted updated API URLS
- Option to enable Plugin in all modes
- Improved reconciliation data query

**Bug Fixes**
- Check Invoices data loader issue with newer versions of WooCommerce
- Black and Whitelist in ZS mode
- Session data cleanup after completed purchase
- Handle non-existing WP_Cart case
- Trigger API requests only on Checkout pages
- Avoid multiple API requests
- Storing of process data with newer versions of WooCommerce

= 2.4.2 =
**Bug Fixes**
- Bugfix fetching customer data

= 2.4.1 =
**Bug Fixes**
- Stability updates and code cleanup

= 2.4.0 =
**Features**
- Support for using the Invoices Payment Method with the new WooCommerce Blocks Checkout view
- The Plugin is now in the official WordPress Plugins store for easier updates
- Security Improvements

= 2.3.6 =
**Bug Fixes**
- fixed a bug where orders had an incorrect order status when using a payment option different from Invoice

= 2.3.5 =
**Bug Fixes**
- fixed reconciliation check functionality checking all orders instead of only riskcube invoices

# WDC Address Normalization

Runtime address normalization no longer calls DaData after the buyer has typed an address line.

The checkout normalization chain is intentionally small:

1. local city context
2. FIAS/GAR placeholder
3. manual fallback

DaData is used only for visual address suggestions in the checkout address picker. The buyer chooses a suggested street/house in the modal, and those selected fields are persisted through checkout hidden fields and order meta.

The local city picker remains the source of the selected city, local postcode, and local location identifiers.

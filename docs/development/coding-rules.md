# Coding Rules

Version: 0.133.5

## Do Not

- Add carrier switches to generic Shipment Framework services.
- Add carrier business logic to `OrderShipmentsMetabox`.
- Add carrier UI to generic JS modules.
- Persist carrier data directly from `ShipmentCreationService`.
- Add document action metadata to shipment adapters or adapter status payloads.
- Download documents inside shipment adapters.
- Create an alternate lifecycle endpoint for one carrier when the common continuation contract fits.
- Add persistence fallbacks that hide missing mapper registration.
- Make a required service optional to mask incomplete DI wiring.
- Add smoke tests outside the regression manifest without a reason.
- Preserve legacy aliases without an active consumer.

## Do

- Register carrier implementations in the composition root.
- Implement carrier-specific adapters, mappers, document providers, modal extensions, and JS extensions.
- Put document action availability, visibility, keys, labels, and action metadata in document providers.
- Keep AJAX capability/nonce/order/carrier checks close to controller entry points.
- Keep repositories focused on storage, not application decisions.
- Update canonical docs with code changes.
- Add regression coverage for new extension points or changed contracts.

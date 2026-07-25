# Current-implementation adapters

Domain adapters belong here and must invoke the current PHP controller or service named by each manifest entry. They may use repositories only to arrange synthetic setup and capture invariant-relevant state. They must never reproduce financial formulas or hand-author expected outputs.

The initial Phase 0D corpus was blocked pending an isolated parity database/bootstrap. The first bound batch now covers `FIX-TXN-001`, `FIX-TAX-001`, and `FIX-BUD-001` through `CurrentImplementationAdapter.php`; all other groups remain explicitly blocked. Adapter captures must continue to invoke authoritative PHP controllers/services and may not hand-author financial expectations.

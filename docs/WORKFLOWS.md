# Workflows

## A. Transport Booking
1. Dealer fills booking form (from/to hub, date, vehicle class, PO upload)
2. System creates job in `pending_verification` status
3. Job number generated (YYMM + 4-digit sequence)
4. Notification sent to Ops Manager

## B. PO Verification
1. Admin opens pending booking (split-screen: details left, PO preview right)
2. Verifies PO matches booking details
3. Approves → status becomes `verified`, then `approved`
4. Or rejects with reason → status becomes `rejected`

## C. Driver Assignment
1. Ops Manager or Dispatcher selects approved job
2. Assigns driver from available pool
3. Status becomes `assigned`
4. Driver receives notification

## D. Driver Execution (PWA)
1. Driver sees assigned jobs on mobile
2. Logs events sequentially: arrived_pickup → vehicle_ready → departed → arrived_delivery → POD scanned → completed
3. Events stored locally (IndexedDB) and synced via Background Sync API
4. Job status transitions: assigned → in_progress → completed

## E. Invoice Readiness
1. When job completed + POD uploaded → status becomes `ready_for_invoicing`
2. Accounts user generates invoice (DomPDF)
3. Status becomes `invoiced`

## F. Cancellation
1. Free cancellation until prior working day cut-off
2. Late cancellation auto-applies penalty (driver cost only)
3. Admin can override penalty with reason (logged)
4. Emergency jobs bypass cut-off rules

## G. Performance Scoring
1. Delay = actual_ready_time - scheduled_ready_time (client-caused only)
2. Scoring: ≤60min = 100%, 61-180min = 50%, >180min = 0%
3. Monthly: if accuracy ≥ 90% AND eligible jobs ≥ minimum → 3% credit note

## Status Flow
```
pending_verification → verified → approved → assigned → in_progress → completed → ready_for_invoicing → invoiced
                    ↘ rejected                          ↗ cancelled (from any pre-invoiced status)
```

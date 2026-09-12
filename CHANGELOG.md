# Changelog

All notable changes to Mining Manager will be documented in this file.

## [Unreleased] — Payment Allocation

Wallet payment verification, rebuilt. A member who sends their tax ISK without pasting the tax code used to leave a transfer that nothing could match and no button could resolve. It can now be assigned to the invoice it was meant for, in two clicks, with the remainder rolling onto their next unpaid invoice and any surplus held as credit.

> Mental model: a wallet transfer is money looking for an invoice. Matching it by tax code is the fast path; assigning it by hand is the fallback. Either way the transfer is claimed exactly once, and every invoice it touches records its slice.

### 🐛 Nine ore families were being taxed as belt ore

Mordunium, Ytirium, Eifyrium, Ducinium, Griemeer, Nocxite, Kylixium, Hezorime and Ueganite
were classified as plain ore rather than abyssal. If you tax abyssal ore, you have been
collecting nothing on any of them.

The check that decides what counts as abyssal was written before those families existed and
has never been widened. The registry has known about them since the last release; the
classifier simply never asked it.

**Anyone taxing abyssal ore should expect their revenue to go up after this release.** It
applies only to mining recorded after you upgrade, exactly like every other classification
change: no invoice already issued moves, and no existing row is rewritten.

If you tax abyssal ore at a rate you set years ago and forgot about, this is the release to
go and look at it.

### 🧹 One ore classifier instead of six

The same ordered "is it moon, ice, gas, abyssal or belt ore" decision existed in six places:
both importers, the backfill command, the event aggregator, the ledger summaries and the tax
calculator. They were copied from each other and had already drifted, one of them answering
`moon_r4` where the other five answered `moon`.

That is why the abyssal gap was in all six at once. There is now a single `OreClassifier`
the six call into. `TypeIdRegistry` is untouched and stays what it has always been: a list of
type ids, not a place where policy lives.

### ✨ Performance Charts can be sliced

Overview and Performance Charts were showing much the same material: three of the five
charts were the same queries in a different container. Charts now has a job Overview
cannot do.

Three filters, on top of the dates and corporation already there:

**Source.** All mining, my moons only, all moon ore, or other moons only. My moons reads
the corporation observer list, so it means what it says rather than guessing from the ore.

**Ore.** Regular ore, moon ore, ice, gas, abyssal, triglavian.

**Player.** Picked by main character and applied across every character that player mines
on, using the same account resolution the payment matcher uses. One question, one answer,
rather than two implementations that drift.

The export button carries the same slice, so a downloaded file matches the page it came
from.

Two things the page tells you rather than leaving you to find out. **Other moons is
inferred**, not recorded: it means moon ore that none of your observers saw, which usually
means somebody else's moon and occasionally means one of yours with no observer. And any
filter that reads ore classification says so when it does, because mining from before the
classification cutover carries the categories it was billed on. Those older rows
under-report the ore families CCP added after the original registry was written, and that
history was left alone on purpose so no past bill can change.

Filtering that finds nothing says so, and says why, instead of showing five empty charts.

Under the surface, the filter is one object that owns both the query it builds and the
cache key that describes it. Every figure on this page is cached for fifteen minutes on a
hand-built key, so a filter added to a query but forgotten in a key would have served
another slice's numbers: no error, no empty result, just plausible wrong totals. Keeping
both halves in one place is what stops that, rather than remembering to.

### 🐛 Allow Data Export decided nothing

The setting had never worked. Two of the views reading it were served by a controller that
did not define the key, two more by a controller that did not pass the flags at all, and
every one of them fell back to its own default and left the button on. A director could
switch exporting off and watch nothing change.

It works now, and it means what it says: **off is off for everyone**, directors and admins
included. It covers mining ledger, tax records, personal exports of both, analytics, theft
incidents and report downloads. Anyone who turns it off can turn it back on, since only an
admin can reach the setting.

The check is on the endpoints, not only on the buttons. An export is a GET with a query
string, so anyone who had used one once still had a working link; hiding the button would
have looked like a fix without being one. Follow an old link with the setting off and you
get told it is off, rather than an empty file.

Your settings backup is deliberately outside this. Exporting your own configuration is not
the same act as taking mining and tax records out of the plugin, and an admin locked out of
their own backup by a data setting would be a worse surprise than the bug being fixed.

### 🐛 The assign dialog said upfront payments were off when they were on

Holding a payment as account balance showed a warning that you were overriding a
switched-off feature, whatever the feature was actually set to. The Tax pages were reading
their feature flags from a list that had four entries in it while the pages asked for more
than four, and the missing one came back through a default as "off". Nothing was wrong with
the setting, the saving, or the matching: only what the dialog said about it.

The tax pages now take their flags from the same set every other page uses, so a flag
cannot be on in one place and absent in another.

### 🐛 Tax Overview opened in what looked like no order at all

It was ordered by amount owed, descending, as text. Sorted that way 87,534,100 comes above
796,982,374, which is why the page looked shuffled. Every money column, every date column
and the period column now carry their own sort key, so they sort as money and dates rather
than as the strings they are printed as.

The page now opens on what needs chasing: overdue first, then unpaid, then partly paid,
then settled, and newest period first inside each group. A part-paid invoice that has gone
past its due date sorts with the overdue ones, which is what the red badge already sitting
next to it says.

The same string ordering was making Tax Codes list periods out of order. Fixed there too.

### 🐛 Tax History put the first half of a month above the second

Both halves of a fortnightly month carry the same month value, so ordering by that left
them tied, and the tie fell to whatever the database returned first. "Jul 1-14" sat above
"Jul 15-31" even though the second half was the later period and the more recent payment.
Ordering is now on the period's own start date, in My Taxes, on the overview, and in every
export that lists tax records.

### ✨ Closing off a tax code that was left behind

A code is marked used by the payment that quotes it. A payment assigned by hand quotes
nothing, so its invoice went paid while the code stayed active and eventually expired. The
result was an expired code against a settled invoice, with nothing on the page able to
resolve it.

Admins get two actions on the Tax Codes tab. **Mark used** closes the code off, and is
offered only where the invoice really is settled: doing it while money is still owed would
take away the reference the member is meant to quote. **Delete** removes a code that should
not exist at all, which the plugin could already do but never showed a button for.

### ✨ Calculate Taxes opens grouped by account

What is being worked out on that page is a bill per player. The flat list is the working
underneath it, and is still one click away.

### 🐛 Three buttons showed their own translation key

"Generating", "Regenerating" and "Errors" were referenced but never defined, so the raw key
appeared on screen in place of the word.

### 🐛 The buttons on Wallet Verification did nothing

Four separate faults, stacked:

- **`toastr` was never loaded.** Every page in this plugin reports through `toastr`, SeAT's layout does not ship it, and neither did we, despite the library sitting unused in our own assets. `toastr.info()` on the first line of Sync and Auto-Match threw before the request was even sent. In Verify and Dismiss it threw in the success handler, so `location.reload()` never ran and a working action looked dead. Now loaded via a shared partial on all 20 pages that use it, backed by a fallback that stands in if the asset ever fails to load, so that failure mode cannot silently kill every button again.
- **Verify could never succeed.** The page lists exactly the donations where no tax code was found, and Verify re-ran the same code-based matcher that had already rejected them. It also looked the transaction up in `character_wallet_journals` while the page reads `corporation_wallet_journals`, so for any payer without a personal wallet token it could not find the row at all. Verification now reads the corporation journal throughout.
- **Sync Wallet Journal returned a 500 every time.** It called `WalletTransferService::verifyPayment()`, which does not exist. PHP raised an `Error`, which the surrounding `catch (\Exception)` does not catch.
- **Failures were reported as success.** A batch where every row failed still answered `200` with "0 payments verified", so the page showed a green toast and reloaded unchanged. Failures now answer as failures and say what is actually wrong.

### 🐛 Partial payments could be credited twice

`mining-manager:verify-payments` guarded against re-crediting by checking `mining_taxes.transaction_id`, a single column overwritten on every payment. An invoice settled in two instalments inside the lookback window lost the reference to the earlier one, so the next run credited it again and the invoice reached "paid" while still short. The command now claims every transaction in `mining_manager_processed_transactions` before touching an invoice, the same guard the rest of the codebase already used.

### 🐛 Tax codes in the reason field were invisible to two of three matchers

EVE puts the note a player types into `reason`; `description` is CCP's generated sentence and never contains the code. The scheduled command read both, but `WalletTransferService::processTransaction()` and the wallet journal listener read only `description`. All matching now reads both fields.

### 🗑️ Removed: a listener that never ran

`ProcessWalletJournalListener` was bound to `Seat\Eveapi\Events\CharacterWalletJournalUpdated`. SeAT has no such event, so it never fired once since it was written. It also read the character wallet journal, the wrong side of a donation. Removed rather than repointed. The scheduled run is and always was the real matching engine.

### ✨ Assign a payment to an invoice

- **Assign to invoice** on every pending row. Shows who paid and how much, lists that player's open invoices (alts included when the accept-alts setting is on), and credits the payment where you point it.
- **Remainder cascades** onto the next-oldest unpaid invoice for that player, and keeps going until the money runs out. One transfer can settle three months. Toggle in Settings → General → Payment Settings.
- **Surplus is held as credit** against the paying character and comes off their next invoice automatically when it is calculated. Listed on the Wallet Verification page. Toggle alongside the cascade setting.
- **Undo**, which reopens the invoices and returns the transfer to the pending queue. Refused when part of the surplus has already gone somewhere else, rather than silently reopening a settled invoice.
- Status badges now say *why* a payment is still waiting: no tax code, unknown code, not yet applied, or before the cutover.
- The "Mismatched" tile used to repeat the pending count. It now means a payment carrying a code that matches no invoice.

### 🐛 Partly paid invoices were never chased, and reminders quoted the wrong number

Two faults in the same family, both older than the allocation work and both made worse by it.

`SendTaxRemindersCommand` and `GenerateTaxInvoicesCommand` selected only `unpaid` and `overdue`. A **partly** settled invoice matched neither, so it was never invoiced for the remainder and never reminded about. It simply went quiet. That was survivable when partial payments barely worked; now that instalments, cascades and account balance all produce them, a partly paid invoice is a normal state.

Where an amount was quoted, it summed `amount_owed` and ignored `amount_paid`, so a member who had paid half would have been chased for the whole invoice. Fixed in all four places that ask someone for money: the scheduled reminder, the invoice generator, the single Send Reminder button and the bulk one. Each now asks for the outstanding balance and skips anything with nothing left to pay.

Payment codes were caught by the same filters and so had the same gap: an invoice that was already part paid got no code, which is the one thing a member needs in order to pay the rest. Codes are now minted when the tax record is created, before anything can change its status. A code identifies an invoice; whether anything is still owed on it is a separate question, and not one that should decide whether it gets an identity at all.

### ✨ Tax reminders account for balance

A reminder or overdue notice that follows a partial drawdown now names how much of the period was already met from the member's balance, on Discord, Slack and EVE mail. Without it the figure is smaller than the invoice they remember, with nothing to say whether that is a discount, a mistake, or their own money.

### ✨ Pay ahead, and a Balances tab to see it

**Upfront payments.** A standing keyword in the transfer reason (default `MM-UPFRONT`, configurable, off by default under Settings → Features) lets a member pay before being invoiced. Unlike a tax code it never expires and is the same for everyone, so it can live in the corp MOTD. The payment settles whatever they already owe, oldest invoice first, and the rest becomes account balance. A tax code still wins if both appear.

Both the keyword and the on/off switch are global rather than per corporation, and are labelled as such in Settings. There is one tax program reading one wallet, and the matcher runs as a single corporation (the configured moon owner), so anything saved against another corporation would never be consulted. The keyword cannot overlap the tax code prefix in either direction, since both are read from the same field and an overlap would make a payment readable as either.

**Balances tab.** One page, two audiences, the way the rest of the tax section works. A director sees everyone holding a balance, the corporation-wide total, and how much has already been applied to invoices; a member sees their own, alt-aware. Every balance lists what it has been spent on, linked to the invoices. The tab hides itself entirely until someone holds a balance or upfront payments are switched on.

### 🐛 Partly paid invoices could dodge the overdue treatment forever

A part payment moves an invoice to `partial`, and `updateOverdueTaxes()` only promotes `unpaid`, so it could never become overdue however late it got. A token payment bought permanent immunity from escalation.

`MiningTax::isOverdue()` has always been date-based and has always been right about this; nothing was asking it. Reminders now do, gated on `payment.overdue_paid_threshold_pct` (default 95) so a genuine near-miss from price drift keeps the gentler wording while a token payment does not. The tax list shows an Overdue badge next to Partial, because showing only "Partial" made the page look calm about something the reminder was already chasing.

### ✨ A weekly digest of who still owes

Directors had no view of the whole picture, so an invoice missed by the per-member notifications went unnoticed. The digest names each member, what is left to pay, the percentage already covered and how many invoices it spans, largest debt first.

It follows the chain rather than a fixed weekday: invoices go out, the due date passes, then the first digest, then every 7 days until everything clears, at which point it goes quiet and resets. Per-webhook opt-in, off by default (migration `000024`), corp-scoped so it never reaches the members it names.

Tax Overview also gains an **Outstanding (not fully paid)** filter covering unpaid, overdue and partial together, which is where the digest links to.

### ✨ Account balance is visible to the person who owns it

Held credit was only ever shown to directors, on the Wallet Verification page. A member who overpaid had no way of knowing the surplus was kept rather than swallowed, and no way of knowing their next invoice was already covered.

**My Taxes** now shows an Account Balance panel, but only when there is a balance to show. It is alt-aware, so a surplus sitting on whichever character sent the ISK is visible against the account it belongs to.

**An invoice that credit paid for says so.** The detail page carries a callout naming the amount, or saying the invoice was settled in full from balance with nothing to pay. The invoice's own notes record it too, so it reaches the exports and the receipt rather than living only in the allocation rows.

The director-side card on Wallet Verification gains a total once more than one character is holding a balance.

### ✨ Payments received

The invoice detail page has a **Payments received** table listing every payment credited to it, with amount, date, origin (matched by code, recorded by hand, rolled over, drawn from credit) and notes. `mining_taxes.transaction_id` only ever held the most recent payment, so instalments were invisible.

### ✨ Older payments stay out of the queue

A payment from before the cutover that carries a valid tax code is withheld from the pending list. The old pipeline recorded only the most recent payment per invoice, so for anything settled in instalments the earlier payments cannot be proven to have been credited even though they were. Left visible, they invite a director to assign a payment that has already been applied, and a manual assignment is deliberate so the cutover guard does not stop it.

Payments from before the cutover with **no** tax code stay visible, because nothing could ever have matched those automatically and they are exactly the ones still needing a human. Same for a code matching no invoice at all.

The page says how many are hidden and why, with a **Show them anyway** toggle. Revealed rows are styled as a warning, and the assign panel repeats the caution before you commit.

### ✨ Verification cutover

The migration stamps `payment.dedup_epoch`. Automatic matching ignores transfers dated before it, so historical records are left exactly as they stand and are never re-examined or corrected. Everything from that point forward is claimed and reconcilable. Assigning an older transfer by hand still works; the guard only applies to automatic matching. `--ignore-cutover` opts a manual run out, and `--reset-month` sets it automatically.

### ✨ Diagnostics

Tax pipeline gains **Step 4b: Payment Reconciliation**. For every invoice settled since the cutover it checks that `amount_paid` equals the sum of the payments recorded against it, and flags transaction claims that produced no allocation. Scoped to post-cutover data by design: older records were credited by a pipeline that kept no breakdown and cannot be reconciled.

### 🧹 Consolidation

Matching and crediting lived in four places that disagreed with each other. `PaymentAllocationService` is now the only thing that credits an invoice; `WalletTransferService` reads and matches; the command schedules and reports. The alt-ownership lookup, previously two hand-synced copies, is a single trait.

Mark as Paid, bulk Mark as Paid and the status dropdown now record a payment row as well, so hand-settled invoices reconcile like any other. Bulk Mark as Paid also marks tax codes used, which it previously skipped.

### ✨ Analytics opens on your own corporation

Every analytics page defaulted to All Corporations, mixing every corp on the install into the charts and totals. That is rarely the question anyone is asking, and on a shared SeAT it quietly shows a director other people's mining before they have chosen to look at it.

With no corporation in the query, the filter now defaults to the viewer's own corporation, and the dropdown lists it first. All Corporations stays as a deliberate second choice rather than the accident you land on.

Two things it is careful about: an explicitly empty `corporation_id` means the viewer chose All Corporations and is honoured, so the choice survives every submit; and the default only applies when their corporation is actually one of the dropdown's options, since filtering to a corporation the select cannot show would leave the page reading "All Corporations" while quietly filtering to something else.

### 🐛 Moon Analytics ignored the month you picked

The filter form carried two inputs named `month`: the visible picker, and a hidden one inside the extraction-mode block added to "keep month in sync". Hiding an element with CSS does not stop it submitting, so both went into the query string on every submit, and PHP keeps the last duplicate. The last one was the hidden field, still holding the month the page had been rendered with, so choosing a new month submitted it and then overwrote it with the old one. The page always came back where it started.

The hidden field was never needed: the visible picker submits from inside its own hidden div in extraction mode, which is what carries the month across. Removed, with a note on the toggle function so nobody re-adds it.

### 🐛 The three Moon Planner notifications posted to Discord with no detail

`formatFieldsForDiscord()` never learned about `extraction_started`, `next_extraction_planned` or `schedule_mismatch`. They fell through to the empty default, so the embed carried a title, a description and a footer and nothing else. The "Next Extraction Planned" ping said the next pull was "planned below" with nothing below it: no refinery, no moon, no date.

Everything else about these three was wired up when they shipped, which is why it went unnoticed: Slack fields, EVE-mail bodies, embed colours, titles and ping text all handled them. Only the Discord field builder was missed. All three now carry the same detail their Slack counterparts already did, and a check of all 23 notification types confirms `custom` is the only one left without fields, which is correct for a free-form message.

Timestamps also pick up an EVE-time label, added only where a sender has not already added one. `detectAndNotifyMismatches()` appends it itself while the other three callers do not, so a shared suffix would have rendered "14:00 EVE EVE" on the mismatch embed.


### ✨ Every ore CCP has shipped since the registry was written

`TypeIdRegistry` carried 401 type IDs and now carries 539. What was missing:

- **IV-Grade for all fifteen classic ores.** The registry held base, II-Grade and III-Grade for Veldspar through Spodumain, and IV-Grade for none of them. They were being classified by a fallthrough rather than by rule.
- **The Exordium 0-Grade tier** — half-yield Veldspar and Scordite for the starter region, plus Pyroxeres 0-Grade.
- **Four X-Grade families** (Raspite, Polycrase, Moissanite, Kangite) and their compressed forms.
- **Prismaticite**, from phased asteroid fields. The SDE files it under Material rather than Asteroid, which is why it had gone unnoticed.
- **Nine gas colours** across the Cytoserocin and Mykoserocin sets, plus 27 compressed gas types and Fullerite-C32.
- **Base Rakovene and Bezdnacine**, and the compressed forms of both, where only Talassonite's had been present.
- 63 batch-compressed variants, recognised by `isCompressedOre()` but deliberately kept out of the priced set, since nothing mines them.

CCP has also renamed every ore variant to a numeric scheme: what was Fragrant Nocxite is now Nocxite II-Grade, Thick Blue Ice is Blue Ice IV-Grade, and so on across the whole game. Type IDs never moved, so nothing was mis-taxed, but the comments in the registry described ore names that no longer exist and have been brought up to date.

That rename is also why the reprocessing tab appeared broken. It resolves pasted ore names against `invTypes` with an exact match, so a member pasting a current in-game name against an out-of-date SDE had their row silently dropped. Updating the SDE fixes it; no code change was needed.

### ✨ New ore categories apply from this version forward

Recognising all of the above is an improvement, but applying it backwards would change what people owe for mining they finished weeks ago. On an install that taxes gas, the nine newly recognised gas types would move out of an untaxed bucket and into a taxed one, and historical bills would grow.

So this release stamps a cutover. Mining that was already in the ledger keeps the rate and categories it was billed on, whatever recalculates it later. Mining from here is classified properly.

Two paths would otherwise have applied it retroactively without anybody running a command: `mining-manager:update-ledger-prices` re-derives the rate from the registry on its nightly run, and `mining-manager:backfill-ore-types` re-stamps flags wholesale. Both now stop at the cutover. `backfill-ore-types` gained `--scope` (`epoch` by default, `all` as a deliberate escape hatch), a `--dry-run`, and a report of every category movement, since each one is a rate change.

The cutover keys on when a row entered the ledger, not when the ore was mined, so importing older mining after upgrading still classifies and prices it correctly.

### 🐛 An issued invoice could be rewritten

`recalculateTax()` and the recalculation path inside `calculateTaxes()` both overwrote `amount_owed` with no check for whether the invoice had already gone out. Running `mining-manager:calculate-taxes --recalculate` could therefore change the total on an invoice a member had already been sent, and already paid.

Both now leave an invoice alone once a payment code has been generated for it, money has arrived against it, or its status says paid or partial. The recalculated figure is logged alongside the stored one rather than replacing it.

The same protection extends to the ledger underneath. `update-ledger-prices` was re-pricing rows inside periods that had already been invoiced — for a fortnightly period closing on the 3rd, the 01:00 run on the 4th would re-price the 3rd's mining after the bill had gone out. It now skips any row covered by an invoice that has been issued. Bills still being worked out are untouched by this and continue to re-price as before.

### ✨ Mining that turns up after the bill is marked, not quietly charged

Corporation observer data does not always arrive before the period it belongs to is
invoiced. When it landed late, the day's summary was rebuilt from scratch and started
reporting more tax than the member had been billed, while the invoice itself stayed put.
Nothing said the two had parted company.

Late mining is now exempt and says so. The row keeps its real quantity and value, carries
a zero rate, and holds a note explaining that it arrived after the period was invoiced.
The entry detail page shows that note beside the zero, because a bare 0 ISK invites
exactly the question it fails to answer.

Daily summaries for an invoiced period keep the tax figure they were billed at, while
volume and value continue to refresh. Freezing the whole row would have left the day's
tonnage looking wrong, which is its own kind of confusion; this way the numbers stay
honest and the money stays settled.

The ISK on genuinely late mining is not recovered. Re-opening a settled invoice, or
chasing somebody for more on a bill they have already paid, is worse.

Note for anyone reading the schema: `is_finalized` plays no part in this. It is derived
from the calendar month, so on a fortnightly cycle a period can be invoiced and paid a
fortnight before that flag turns over. The test used here reads the invoice periods
themselves and does not care how long they are.

### ✨ Give held balance back

Somebody meant to pay 5b and sent 50b. Somebody is leaving and the corporation should not
keep money that is theirs. Until now the only answer was to send the ISK back in game and
hope somebody wrote it down, while the plugin carried on insisting they still had a
balance.

The Balances tab has a **Refund** action on each held balance, for directors. Full or
partial, with a reason, which is required because it is the only record of why. Leaving
the amount empty refunds whatever is left, which is what somebody leaving the corp wants
without retyping a figure they would only get wrong.

**Nothing sends ISK.** EVE has no API for moving money, so a director makes the transfer
in game. What the plugin does is take the amount off the balance the moment the refund is
recorded, so what a member is owed is right straight away, and then watch the corporation
wallet for the ISK actually leaving. When an outgoing transfer to that character for that
amount turns up, the refund is marked as sent and the transaction is attached to it.

Until that happens the refund reads **Awaiting transfer**, and the total of everything
waiting sits at the top of the page. That number is the one thing there that is a promise
rather than a fact: money agreed to be returned and not yet gone.

A refund is recognised by a keyword in the transfer reason, `MM-REFUND` by default and
configurable next to the upfront one. The dialog shows the keyword to copy before you go
and send the ISK. It is not decoration: an SRP payout, a ship reimbursement and a refund
are all the same kind of wallet entry to the same person, and without something to tell
them apart a reimbursement that happened to match a refund would take a real debt off the
books. The keyword cannot overlap the tax code prefix or the upfront keyword, since all
three are read from the same field.

Refunds are looked for across every wallet division, since tax arrives in a configured one
but a refund leaves from whichever division had the ISK. If two transfers both fit,
neither is chosen and the refund stays pending: marking money returned when it might not
have been is worse than a person looking at it.

For the transfers that will never match, there is **Mark as sent**. A keyword left off, a
payment by contract, ISK out of a wallet the plugin cannot read: none of those will ever
be found, and without a way to close them the refund sits pending for good and the
outstanding total quietly stops meaning anything. Marking one sent asks for a short note
saying why, and the row afterwards reads **Sent (by hand)** rather than **Sent**. That is
deliberate. A matched refund is backed by a transaction anybody can go and look at; a
hand-confirmed one is a director's word, and the page should not present the two as the
same thing. **Undo** puts it back on the pending list if the wrong row was closed, which
is easy enough to do when somebody has two refunds the same size. Refunds matched to a
real transfer cannot be undone: there is nothing to correct, and the next run would only
match them again.

One consequence worth knowing. A refund closed by hand claims no transaction, so if the
transfer that actually paid it turns up later carrying the keyword, that transfer is left
alone rather than handed to another refund of the same size for the same person. Those
refunds stay pending and the run says so.

A refund can be sent to **any character on that player's account**, not only the one whose
balance it came off. That balance often sits on a mining alt while the player only logs in
on their main, and requiring the ISK back to the alt would have left perfectly good refunds
pending forever. This is the same **Treat a player's characters as one account** setting
that tax and upfront payments already read, so an install where each character really is
its own account stays strict on all three. When the refund is confirmed against a character
other than the one holding the balance, both ids are recorded.

The setting's description now says what it actually governs. It described tax payments
only, which was true when it was written and has not been for a while: upfront payments
have always read it too, and refunds now do.

### ✨ Switching upfront payments off leaves held balances alone

Turning the feature off stops members adding to a balance with the keyword. It does not
touch balances already held: they stay spendable and keep coming off invoices, because
the money is the member's and turning off a feature is not a reason to keep it.

Two routes deliberately stay open with the feature off, and both now say so. **Hold
surplus as credit** is a separate switch, so somebody can still build a balance by
overpaying an invoice; the setting now spells that out and points at the other switch if
you want the route closed. And a **director can still bank a payment by hand**, because a
codeless transfer from somebody who owes nothing has nowhere else to go: the dialog now
carries a warning that doing so overrides a setting somebody chose.

### ✨ Assign a codeless payment to a player's balance

Assigning a payment by hand only worked if there was an invoice to point it at. A
transfer with no tax code from somebody who owes nothing was a dead end: the dropdown
was empty, the button was disabled, and the only ways out were to leave it in the queue
or dismiss it. Neither is true, because the money did arrive.

The dropdown now offers **Hold as account balance** alongside the invoices, and picks it
automatically when the player has nothing outstanding. It behaves exactly as a payment
using the upfront keyword does, because it is the same thing arriving by a different
route: it settles anything they already owe, oldest invoice first, and holds the rest
against future invoices.

The button says which of the two it will do, and the remainder option disappears when
holding as balance, since that is what holding as balance already means.

### 🐛 Nightly re-pricing ignored which ore types you actually tax

Three places work out a tax rate. Import asks the tax selector which categories are
charged. The daily summaries ask as well, and those are what invoices are built from.
`getTaxRateForOre()`, which the nightly `mining-manager:update-ledger-prices` uses,
never asked at all: it looked up the configured rate for the category and returned it,
so on an install that taxes no ore it still answered 10%.

Invoices were never affected, because they come from the daily summaries. What could be
wrong was `mining_ledger.tax_rate` and `tax_amount` on recent rows, and those are shown
on the entry detail page - so a member could open a row and read a tax figure for
mining that is not taxed and appears on no bill.

It now asks the same question the other two do, through the same helper, and is handed
the ledger row so the "only corp moon ore" rule can be evaluated properly rather than
falling back. All three paths were then checked against a real settings profile and
agree on every ore category.

Also aligned a stray default: the summaries treated an absent gas setting as taxed while
everything else treated it as untaxed. Unreachable, since the selector always supplies
every key, but two opposite defaults for one setting read as disagreement.

### ✨ The reprocessing calculator says what it did not recognise

An ore name the calculator could not resolve was dropped without a word. The row
simply left the totals, which looks exactly like the ore being worthless, and that
ambiguity is why a stale static-data file took two reports and a database dig to
identify rather than five minutes.

Names that cannot be placed are now listed above the results, with the totals
explicitly described as excluding them, and a note that a name which looks right in
game usually means the local static data is behind.

### 🐛 Settings export could not represent a multi-corporation install

The export keyed its map on the setting name alone and threw `corporation_id`
away. Most keys exist several times over, once globally and once for each
configured corporation, so they collapsed into one entry each and whichever row
the database returned last silently won. Import then wrote everything back into a
single context. Webhooks were never in the file at all, because they live in their
own table and the export only read the settings one.

Settings now export as rows that each carry their own corporation, and import
writes each row into the context it names. Files produced by the old export still
import exactly as before, treated as global, and say so afterwards.

Webhooks can now travel too, but only when asked for: a webhook URL is a
credential, and anyone holding the file can post to that channel. The checkbox
says as much, and delivery statistics stay behind with the install that earned
them. Re-importing matches on name and corporation, so it updates rather than
piling up duplicates.

### ✨ Six commands that could run twice at once now hold a lock

`backfill-ore-types`, `backfill-event-records`, `backfill-extraction-history`,
`backfill-extraction-notifications`, `restore-data` and `initialize` all write, and
none of them checked whether another copy was already running. Two at once would
duplicate work at best and interleave writes to the same rows at worst. Every other
writing command in the plugin already took a lock; these now do too, released in a
`finally` so a failure cannot leave one stuck.

### ✨ --all-unpriced says how much it is about to touch

The flag drops the date window entirely. Invoiced periods are excluded from
re-pricing now, so it can no longer disturb a bill, but it can still rewrite value
and rate across all of history in one go. It reports the count and asks first.

### 🐛 restore-data could leave constraint checks switched off

The restore disables foreign key checks and turned them back on at the end, but an
exception on the way there skipped that line and left the connection accepting rows
it should have rejected. It is a `finally` now.

### 🐛 Moon Planner: the schedule-mismatch alert could never finish

Every mismatch warning failed with `Unknown column 'moon_name'` and the notification
never went out. `detectAndNotifyMismatches()` calls `loadDisplayNames()` so the alert
can name the moon and the refinery, and that helper attaches `moon_name` and
`structure_name` to the model. Neither is a real column. Saving the "already warned"
latch through the model then tried to write them too, because Eloquent saves every
dirty attribute rather than only the one it was handed.

The latch is now written through the query builder, which touches one column and
cannot pick up display fields. `loadDisplayNames()` carries a note about the trap,
since the same shape would break any future caller that saves a model it has been
through.

### ✨ Resetting a month of payments now asks first

`mining-manager:verify-payments --reset-month=YYYY-MM` un-does payment matching for a
whole month: invoices go back to unpaid, the ISK recorded against them is cleared,
allocation rows are deleted, released transactions become claimable again, and payment
codes members are already holding go live a second time. That is a reasonable thing to
want after a bad match, and a very unreasonable thing to do to the wrong month by
accident, which is what a bare `YYYY-MM` and no confirmation allowed.

It now shows what is at stake before it does any of it: how many invoices are in scope,
how many are actually paid or partial, the ISK about to be cleared, the allocation rows
and payment codes involved, and a list of the invoices themselves. Then it asks.

`--dry-run` prints all of that and stops. `--force` skips the prompt for scripted use.
With neither, a non-interactive run cancels rather than proceeding on a default.

### 🐛 Dedup could rewrite mining that had already been billed

Cross-source dedup exists because the character endpoint and the observer endpoint
both report the same mining, so one of the two records has to give way. It ran on
anything up to thirty days old and took no interest in whether an invoice already
covered it. A late-arriving observer record could therefore delete or shrink a
ledger row sitting behind an invoice a member had been sent and paid, leaving the
bill with nothing to stand on.

Three places did this: the import-time dedup and the orphan sweep in
`mining-manager:process-ledger`, and the orphan sweep in
`mining-manager:update-daily-summaries`. All three now leave covered rows exactly as
they are and log each one they skip, with `process-ledger` reporting the count at the
end of its run.

The rule for what counts as issued now lives in one place rather than being written
out slightly differently in each caller, and `mining-manager:update-ledger-prices`
was moved onto it too.

### 🐛 The price coverage report showed impossible percentages

`mining-manager:diagnose-prices --show-coverage` carried its own hardcoded counts, which had drifted from the registry, so it reported Gas as 16 of 12 items and Regular Ores as 46 of 45. The denominators now come from the registry itself and cannot drift again.

### 🐛 Moon Planner: saving a planned pull threw a database error

The planner resolved a refinery's moon by reading a `moon_id` column off `corporation_structures`. SeAT has no such column there. Saving a planned pull put that read in the SELECT list, so it failed outright with `SQLSTATE[42S22] Unknown column 'moon_id'` and the pull was never stored.

The same wrong assumption elsewhere failed quietly instead. Auto-fill and the refinery sidebar reached for `$refinery->moon_id` on an already-loaded model, where Eloquent returns null for an attribute it never selected rather than complaining. So every auto-filled plan was stored with no moon, the calendar showed **Unknown Moon** on every entry, and the refinery panel showed no moon names at all.

A refinery is anchored on exactly one moon and cannot move, so the moon is recoverable from any extraction ever seen for that structure. `MoonPlannerService` now resolves it from our live extractions, then our archived history, then SeAT's own extraction table, memoised per request and resolved in bulk rather than per refinery. `refineriesForCorporation()` attaches it, so every caller reading `$refinery->moon_id` gets a real answer. Moving a plan also fills in a moon that was not known when it was created.

Migration `000023` backfills the plans and audit rows already saved without one. Data only, idempotent, and it leaves a refinery nothing has ever observed as null, which the column allows.

Because the browser never sent a `moon_id` in the first place, that fallback branch was taken on every single save: **placing a pull by hand has never worked**, on any refinery, on any day. Auto-fill was the only way to get a plan onto the calendar. Saving also now checks the structure is a refinery this corporation owns, instead of accepting any integer and producing a plan captioned "Structure 12345" that nothing could ever reconcile.

### Schema

- New tables `mining_manager_payment_allocations` and `mining_manager_payment_credits` (`000022`).
- `000022` also backfills `mining_manager_processed_transactions` from the transaction ids already recorded on invoices and tax codes, so the new guard recognises what the old pipeline credited, and stamps the payment cutover.
- `000023` fills in the moon behind each existing extraction plan, so plans made before the planner could resolve one stop showing an unknown moon.
- `000024` adds the webhook column for the outstanding digest.
- `000025` stamps the ore classification cutover.
- `000026` adds `mining_manager_payment_refunds`.
- `000027` adds who confirmed a refund by hand and why.

All six are additive. No existing column is altered and no data is rewritten.

## [2.0.3] — 2026-07-24 — The Ecosystem Era: The Moon Planner

The Moon Extraction Planner: a corp-internal calendar for staggering refinery pulls so chunks don't clump faster than a small crew can mine them. SeAT can only read the extractions a director fires in-game, so the planner is a coordination tool — it never controls the structure. Additive: one new permission, two new tables, a handful of columns, three opt-in notifications. No new ESI scopes.

### Moon Extraction Planner

- New **Moon Planner** page under Moon Manager, gated by the standalone `mining-manager.moon_manager` permission (directors and admins included).
- Shows three months at once, all in **EVE time (UTC)** — the clock the in-game structure scheduler uses. The add/edit form takes EVE time and confirms what that is in your local zone.
- **Auto-fill from history** projects each refinery's pulls from its arrival cadence (needs 2+ past arrivals) and spreads them to honour a configurable minimum gap (default 24h, set in Settings → Notifications). Refineries with too little history of their own use the corp-median cadence.
- **Re-anchor** a recurring day by moving a pull — future projections follow the new day.
- **Minimum-gap guard**: placing a pull within the gap window of another arrival asks for confirmation, enforced client- and server-side.
- Live and completed extractions are **locked** — they're set in-game, so the planner records them and can't edit them. Archived pulls stay visible.
- Per-refinery panel with cadence, next projected pull, a coverage badge (`Planned N×` / amber `Not planned` for a skipped moon) and a highest-ore-tier badge (R4–R64). Uncovered refineries sort to the top.
- **Change history**: who created, moved or removed each planned pull.

### Notifications

- **Extraction Started** — a refinery lit its drill. With Manager Core installed it's detected in about 2 minutes via ESI fast-poll instead of the ~30-minute moon-extraction endpoint cache. Toggle at Settings → Notifications → Extraction Started — Detection Speed.
- **Next Extraction Planned** — after a chunk is ready, announces the refinery's next planned pull.
- **Moon Scheduled Off-Plan** — a moon's in-game extraction is set more than 30 minutes off the plan; flagged on the calendar and pinged once.

All three are per-webhook opt-in and off by default.

### Schema

- New tables `moon_extraction_plans` (`000019`) and `moon_extraction_plan_audits` (`000020`).
- Additive columns on `moon_extractions` and `webhook_configurations` (`000019`, `000021`). All defaulted, no backfill.

## [2.0.2] — 2026-05-31 — The Ecosystem Era: Field Repairs

Bug fixes for issues that surfaced in production after v2.0.1: the moon-chunk-unstable warning silently never firing, the notification dispatcher skipping without saying why, the Mark-as-Paid modal freezing on click, and tax payments from alt characters being rejected. All additive, no schema changes, no new ESI scopes.

### 🐛 Critical bug fixes

**Moon-chunk-unstable notification silently never fired in production.** Root cause: `MoonExtractionService::determineStatus()` treated ESI's `natural_decay_time` field (the auto-fracture mark, ~3h after chunk arrival) as the chunk's expiry point. Every chunk got stamped `status='expired'` ~3 hours after arrival even though it had **50 more hours of mineable life** ahead of it. `CheckExtractionArrivalsCommand` Pass 2 filters `whereNotIn('status', ['cancelled', 'expired'])`, so the wrongly-stamped row was invisible to the cron and the capital-safety warning never fired.

Fix mirrors `MoonExtraction::scopeExpiredByTime()` math — the model has had the correct lifecycle logic all along, it was just `determineStatus()` that drifted apart. Prefers `fractured_at + 50h`, falls back to `natural_decay_time + 50h` as a conservative auto-fracture estimate when `fractured_at` isn't yet populated. Either way the +50h offset matches the actual chunk lifecycle (48h ready + 2h unstable). The UI's "Ready since" / "Unstable in 2h" pills were always correct because they used the runtime helpers (`getUnstableStartTime()`, `getExpiryTime()`); only the persisted `status` column was wrong, and only the cron read `status` directly.

Signature change: `determineStatus(array $data, ?MoonExtraction $existing = null)`. Update path passes `$existing` so the method can read `fractured_at` off the model. Create path (no existing row, so no fractured_at to read) calls without `$existing` and falls through to the `natural_decay_time` fallback — same as before for new rows.

**Recovery for installs already affected** (one-shot, run after deploy):

```sql
UPDATE moon_extractions SET status = 'ready'
WHERE status = 'expired' AND fractured_at IS NOT NULL
  AND DATE_ADD(fractured_at, INTERVAL 50 HOUR) > NOW();
```

After deploy, wrongly-stamped rows also self-heal on the next ESI import (~hourly) — the SQL just shortcuts the wait. Without it, you might miss the 2h unstable warning window for any chunk currently inside it.

**Notification dispatcher silently skipped with no reason.** `NotificationService::send()` had a silent `['skipped' => true]` early-return (no `reason` key) when the `isEnabled()` channel gate failed. The Diagnostic Notification Testing tab showed `reason: unknown`; operators couldn't tell whether the dispatcher had succeeded silently or failed silently. The combination of this + the lifecycle bug meant the moon_chunk_unstable warning could be silently broken at both the trigger layer AND the delivery layer with zero observable signal in any log line.

Fix: dispatcher now calls a new `describeChannelGateFailure()` helper that re-reads each channel signal (EVE Mail / Slack / webhook count) and constructs a specific human-readable reason — *"No enabled channel found: EVE Mail off, Slack off, 0 webhook(s) enabled. Tick at least one webhook in Settings → Webhooks, or enable EVE Mail / Slack in Settings → Notifications."* The reason is in both the API response (so the Diagnostic page shows it) and a WARNING line in `laravel.log`.

Companion fix: `hasAnyEnabledWebhook()` previously caught Eloquent exceptions silently and returned `false`, making every notification skip with no breadcrumb if any model/query issue ever surfaced. The catch block now logs the actual exception at WARNING level so the underlying cause appears in `laravel.log` instead of being swallowed.

**Mark-as-Paid modal froze on click.** Classic Bootstrap 4 modal interaction with AdminLTE: SeAT's `.content-wrapper` creates a local CSS stacking context (transform/filter/perspective on an ancestor). Bootstrap inserts `.modal-backdrop` at `<body>` level (z-index 1040), but the modal-dialog stays inside the wrapper and its z-index gets pinned to the local context. The backdrop ends up effectively above the modal-dialog in the stacking order; clicks land on the backdrop and the form looks frozen. The dialog still renders visually because the backdrop is translucent — so the bug was easy to misdiagnose as a JS handler issue.

Fix: reparent the modal element to `<body>` before show, escaping the wrapper's stacking context. Four direct `.modal('show')` call sites patched with `.appendTo('body').modal('show')`; one declarative `data-toggle="modal"` trigger got a delegated `show.bs.modal` event handler that does the same reparenting (no JS call site to patch).

Five surfaces total: `#markPaidModal` × 2 (taxes index + details), `#eventModal` (events calendar), `#extractionModal` (moon calendar), `#entryDetailsModal` (ledger index). Same latent bug would have hit all five but was less noticeable on the others until you tried to fill a form.

**Tax payments from alt characters silently rejected.** `WalletTransferService::processTransaction()` required the paying character (`transaction.first_party_id`) to be **exactly** the taxed character (`mining_tax.character_id`). Players routinely send tax ISK from their wallet-richest alt rather than the alt that did the mining — those payments dropped on the floor as 'unmatched' even though the tax code in the transaction description was correct.

Fix: the auto-match now accepts a payment if the tax code matches AND the paying character shares a SeAT `user_id` (via `refresh_tokens`) with the taxed character — i.e. is a recognised alt of the same player. New helpers `getCharacterIdsForUserOf()` + `sharesSeatUser()` encapsulate the lookup. Two call sites updated: `processTransaction()` (the listener-triggered auto-match path) and `manualMatch()` (the transaction-to-tax pairing entry point used by the tinker recovery recipe).

New setting `payment.accept_alt_characters` (default `true`) governs the behaviour. UI toggle exposed in **Settings → General** as a switch directly below "Auto-match wallet payments". Default value means the alt-aware behaviour is on automatically; operators who want strict pre-v2.0.2 matching opt in explicitly.

Audit log fires INFO when a payment is credited through an alt (paying char ≠ taxed char). `laravel.log` carries the tax_code, both character IDs, the transaction_id, and the amount. Same-character payments stay quiet. Directors disputing "who actually paid?" can grep the log for `"Payment credited via alt character"`.

**Parallel-implementation gap closed.** `VerifyWalletPaymentsCommand` (the artisan `mining-manager:verify-payments` command) had its own copy of the tax-code lookup loop rather than delegating to the service. The alt-aware fix in the service didn't reach the artisan command, so `--auto-match` kept using strict matching until a follow-up commit applied the same alt-aware logic inline. The artisan warn line now includes the searched-character list when alt mode is on — *"Tax code 'XYZ' not found for character N (searched M linked characters: ...)"* — so failure cases surface the eligible-id set in the operator output.

### 🚀 Permanent backstop

**New `mining-manager:validate-lifecycle-integrity` daily cron.** Permanent backstop against the class of bug that produced the lifecycle status incident: walks `moon_extractions` rows updated in the last 14 days (configurable via `--days`), computes the expected status via the same `fractured_at + 50h` math the runtime helpers use, and warns when persisted status diverges. With `--fix`, applies corrections in place.

Scheduled daily at 03:00 UTC via `ScheduleSeeder` with the `--quiet-ok` flag (no output when there's nothing to report — so the daily cron line stays a no-op when everything's healthy). Non-zero exit code on divergences so SeAT's schedule history reflects "needs attention" even when stdout is quiet.

Three flags:
- `--fix` — apply corrections in place. Each correction logged at INFO with from/to status.
- `--quiet-ok` — suppress success output. Used in the cron schedule line.
- `--days=N` — bound the audit window for performance on large installs (default 14).

Statuses skipped: `'cancelled'` (operator-applied; not derivable from time) and `'fractured'` (legacy/transient).

Diagnostic surfacing: the cron's category in Health Checks → Scheduled Jobs is `integrity` — distinct from `moon` / `metenox` / `tax` / etc. — so a failing audit stands out as a data-integrity signal rather than a routine extraction op.

Operator workflow when the cron flags a divergence:
1. Open Diagnostic → Health Checks → see the `integrity` cron's last exit code.
2. Run `php artisan mining-manager:validate-lifecycle-integrity` manually to see the divergence list.
3. Investigate the rows, or re-run with `--fix` to auto-correct.

### ⚙️ Modified surfaces

- `src/Services/Notification/NotificationService.php` — new `describeChannelGateFailure()`; `send()` skip carries a real reason; `hasAnyEnabledWebhook()` logs swallowed exceptions
- `src/Services/Moon/MoonExtractionService.php` — `determineStatus()` rewritten + signature change + caller updated
- `src/Services/Tax/WalletTransferService.php` — `processTransaction()` + `manualMatch()` now alt-aware; new `getCharacterIdsForUserOf()` + `sharesSeatUser()` helpers; INFO audit log on alt-credited payments
- `src/Services/Configuration/SettingsManagerService.php` — `getPaymentSettings()` + `getGeneralSettings()` expose `accept_alt_characters`; `updateGeneralSettings()` whitelist extended
- `src/Console/Commands/VerifyWalletPaymentsCommand.php` — alt-aware match parity with the service; enriched warn output naming the searched-character set
- `src/Console/Commands/ValidateLifecycleIntegrityCommand.php` — new (daily integrity cron)
- `src/Http/Controllers/SettingsController.php` — validation + checkbox conversion for `payment_accept_alt_characters`
- `src/Http/Controllers/DiagnosticController.php` — `integrity` category in `systemStatusScheduledJobs()` categoriser
- `src/Database/Seeders/ScheduleSeeder.php` — daily 03:00 UTC entry for the validator with `--quiet-ok`
- `src/MiningManagerServiceProvider.php` — `ValidateLifecycleIntegrityCommand` registered
- `src/Resources/views/settings/tabs/general.blade.php` — new alt-payments toggle below auto-match
- `src/Resources/views/taxes/index.blade.php`, `taxes/details.blade.php`, `events/calendar.blade.php`, `moon/calendar.blade.php`, `ledger/index.blade.php` — modal `appendTo('body')` patches (4 call sites + 1 event handler)

### ⚠️ Compatibility

- **Fully additive.** No existing schema changes. No public API change. No breaking setting changes. No new ESI scopes.
- New setting `payment.accept_alt_characters` defaults to `true` — the alt-aware behaviour is on automatically; operators who want strict mode opt in via Settings → General.
- `determineStatus()` signature is backward-compatible — `$existing` is optional with default `null`, so any external code calling the old signature keeps working.
- The validator cron's schedule row is seeded via `firstOrCreate` (canonical `AbstractScheduleSeeder` pattern) — existing operator cron customisations are preserved.
- The modal `appendTo('body')` patch is purely additive — modals that were working continue to work; the broken ones now also work.

### 🔧 Operator recipes

**Re-run historical payment matching** to recover alt payments that were dropped on the floor before deploy:
```bash
docker exec -it seat-docker-front-1 php artisan mining-manager:verify-payments --days=30 --auto-match
```
`--days=30` covers a month of wallet history. Catches alt payments retroactively now that the matcher is broader.

**Manually pair a specific transaction to a specific tax row** (handy for the double-payment-from-two-alts edge case where you want to control which transaction credits):
```php
// in tinker
app(\MiningManager\Services\Tax\WalletTransferService::class)->manualMatch($transactionId, $taxId);
```
Uses the alt-aware sharesSeatUser check; logs an audit line; returns true on success. There's no UI for this in v2.0.2 — if alt-payment double-sends become recurring, a UI control would slot into v2.0.3.

---

## [2.0.1] — 2026-05-26 — The Ecosystem Era: Polish Pass

Polish on top of v2.0.0's ecosystem features: faster director workflows (Discord role picker, notification routing map, Metenox cargo readout), cross-plugin event publishing, and per-surface quality lifts (live local-time conversion, hardened jackpot rendering, aligned diagnostics). All additive, no new ESI scopes.

### 🎉 Headline features

**Inline Discord Role Picker** — Each of the 17 notification types with `has_role_ping: true` (tax / event / moon / theft / report families) now has a "Pick" button next to its Discord Role ID input. Click it → an inline list of every Discord role known to your SeAT install slides down. Pick one → the snowflake fills the input → done. No more "enable Developer Mode in Discord, right-click role, Copy ID, paste here, repeat × 17". Detects all installed providers via table presence (`discord_roles` for SeAT Broadcast, `seat_connector_sets` for warlof/seat-connector, legacy `warlof_discord_connector_roles`). Any combination is supported; role lists merged + deduped by Discord snowflake. One AJAX fetch per page load, shared cache across all pickers. Picker buttons render conditionally on detected providers — installs with no Discord plugin keep the plain text input as the fallback.

**Moon Extraction EventBus Publishing** — Three new events published via Manager Core's Topics facade, fired exactly once per extraction per lifecycle stage:
- **`mining.extraction_ready`** — chunk has fractured, 48h fleet-able mining window opens
- **`mining.extraction_unstable`** — final 2h capital-safety window before expiry (48-50h after fracture)
- **`mining.extraction_expired`** — window closed, no more mining

Rich payload per event: extraction_id, moon_id/name, structure_id/name, corporation_id (for visibility scoping), full lifecycle timestamps, auto_fractured / is_jackpot flags, estimated_value, effective status, `schema_version=1`, plus a `url` field deeplinking to MM's per-extraction detail page so subscribers can pivot operators straight there. New cron `mining-manager:scan-extraction-events` runs every 5 minutes and uses per-stage latches in the new `moon_extraction_event_log` table to publish only stages not yet latched. Catch-up logic backfills earlier stages if a drill is first observed in `unstable`/`expired`. Standalone-safe via `class_exists` guard on `\ManagerCore\Topics`.

**Notification Routing Map** (Settings → Routing Map) — Read-only delivery snapshot showing every notification type, the webhooks it fires through, the corp scope, and the Discord role that will actually be mentioned at send time. Resolved with the same precedence the dispatcher uses (L1 per-type role / L2 webhook legacy role; tax_invoice hard-blocked from role pings). Summary chips: total / globally enabled / delivering / "enabled but firing nowhere" (warning). Flags `extraction_at_risk` / `extraction_lost` as dormant when Manager Core or Structure Manager is missing. Resolved role pills show the role NAME + colour from the Discord provider, not the raw snowflake ID. Mirrors Structure Manager v2.0.0's pattern.

**Metenox Drill Cargo readout** (Director-only, with admin scope picker) — New sidebar page `Mining Manager → Metenox Cargo` (also as a tab on the Moon Extractions section). One card per Metenox Moon Drill, showing what's currently in the drill's `MoonMaterialBay` — every ore stack with quantity, **m³ volume**, ISK value at current market prices, and percent-of-cargo bars. Per-drill **bay fill indicator** with a color-graded progress bar (green/yellow/red) showing `X / 500,000 m³ · YZ% full`. Header chips: drill count · ISK in cargo · **Avg bay fill % (with critical-bay warning)** · oldest data sample. Drills sorted by ISK descending so the most valuable cargo shows first. ISK valuation uses MM's existing `PriceProviderService` (Manager Core's pricing when configured; Jita / Fuzzwork fallback), batched into one round-trip per page render. Cross-plugin contract: PluginBridge capability `mining.metenox.cargoSnapshot($structureId)` returns `[type_id => quantity]` for any Metenox. Data source is SeAT's existing `corporation_assets` table (~1h ESI cache). No new ESI scopes. **System labels** show solar-system names (joined from `solar_systems.name`) with the numeric id as a small muted suffix; falls back to id-only when the SDE row is missing.

**Scope model:** Directors see only the **Moon Owner Corporation**'s drills (matches the Past Extractions table convention — keeps the page and the `metenox_cargo_full` notification aligned). Operators with `mining-manager.admin` land on the same Moon Owner Corp default but get a **corp scope bar** above the chips: a dropdown of every corp with at least one Metenox plus an "All corps" aggregate option, a one-click "Back to Moon Owner" shortcut whenever they're off the default scope, and a hint that the picker only affects this page (notifications still scope to Moon Owner Corp). If the Moon Owner Corp isn't configured, directors see a warning with a one-click link to Settings; admins fall back automatically to the All corps view so they can still browse drills.

**Metenox Cargo Bay Full notification** — New `metenox_cargo_full` notification type fires when a drill owned by the Moon Owner Corporation crosses the configured fill-% threshold going up (default 85%, operator-configurable 50-99%). Yield-stopping warning specifically — drilling stops when the bay caps out but the structure itself stays safe (different from `extraction_at_risk` which is structure-safety). New cron `mining-manager:scan-metenox-cargo-fill` runs every 5 minutes, scoped to Moon Owner Corp only so the scanner and the page show the same set of drills. Dedup latch in the new `metenox_cargo_alert_state` table prevents repeat fires while still over threshold; resets implicitly when cargo is pulled (fill drops back below threshold). Includes ISK valuation of cargo in the bay + deeplink to the Metenox Cargo page. Standalone — no Manager Core or Structure Manager required (works on bare-MM installs).

Bay capacity is **500,000 m³** for every Metenox, sourced from `dgmTypeAttributes` (attribute 5693, Metenox-only) and cross-verified against EVE Ref's published value at https://everef.net/types/81826 ("Moon Material Output Bay Capacity: 500,000 m³"). At typical Metenox production rates (~1,500-2,000 m³/hour) the bay fills in ~10-14 days, so the default 85% threshold gives operators ~2 days of lead time before the bay caps and drilling stops.

**Local time auto-conversion + live countdowns** — New `eve-time.js` (copy from SeAT Broadcast v2.0.0 canonical) wraps every server-rendered EVE timestamp. Hover any timestamp → tooltip with full local time formatted via `Intl.DateTimeFormat` against the browser-detected IANA timezone (same mechanism Discord / Google Calendar / GitHub use; DST handled automatically). High-priority surfaces (active extractions, upcoming events, calendar, my-events) opt into an inline " · HH:MM local" pill via `data-show-local` for at-a-glance reading. New `eve-countdown.js` (MM original, ~80 LOC) replaces `Carbon::diffForHumans()` text with a 1-second tick loop. Color-graded: green (>1d), yellow (1d-1h), red+bold (<1h), muted grey (past target). Event create / edit forms gained an `Enter time in: [EVE/UTC | My local]` toggle. Live confirmation box shows both interpretations as the operator types. On submit JS rewrites the value to UTC-as-datetime-local so the server receives canonical UTC regardless of mode. DST-safe via the browser's IANA timezone.

**Diagnostic page aligned to the suite-wide standard** — Default landing tab is now **Health Checks** (renamed from "System Status"). Nav reordered: Tier 1 universal tabs first (Health Checks → Master Test → System Validation → Settings Health → Data Integrity → Tax Trace), then Notification Testing, then plugin-specific traces, then DEV-only "Test Data" (with red `DEV` badge). Every Tier 1 tab opens with a "What this tab does / When to use / Heads up" intro box. Default landing eager-loads Health Checks data on page open.

**Diagnostic page covers the new Metenox cargo subsystem** — Health Checks lists the scanner cron under a new `metenox` category and reports drill / MoonMaterialBay / alert-latch row counts in Data Counts. System Validation gets a dedicated "Metenox Cargo Subsystem" card with seven server-side health probes (type 81826 in invTypes, migration 000017 schema bits, solar_systems populated, threshold setting in 50-99 range, scanner cron registered, Moon Owner Corp set) plus an overall Healthy/Warnings/Critical pill. Settings Health now iterates the Notifications group so the new `notifications.metenox_cargo_full_threshold_pct` setting surfaces. Data Integrity gets three Metenox-specific checks: stale latch rows (alerted > 60 days ago), orphan latches (referencing structures no longer in corporation_structures), and orphan MoonMaterialBay asset rows (parent missing or wrong type). Notification Testing gains a `metenox_cargo_full` entry in the dropdown with realistic default test data (92.4% fill, 450k m³, 850M ISK) so operators can smoke-test the new alert end-to-end without waiting for a real drill to fill.

### 🎨 Quality of life

**Jackpot rendering hardened against custom SeAT themes** — 18 inline-styled jackpot elements across 6 blades (Report Jackpot button, JACKPOT banners, every "2x multiplier" indicator badge) converted to override-resistant `.mm-jackpot` / `.mm-jackpot-badge` / `.mm-jackpot-alert` CSS classes with `!important` on background/color/border + nested icon colour. Custom SeAT theme installs (`custom-layout.css`) that use `!important` on `.btn-warning` no longer wash the black text out, leaving "yellow text on yellow button" invisible-on-hover renderings.

**Help & Documentation refreshed** — New "Time display & timezones" section under Events with live browser-TZ readout. New "Metenox cargo readout" section under Moon Mining covering data source, refresh cadence, permission model, ISK valuation, cross-plugin contract. "What's New in v2.0.1" section on the Overview page so operators upgrading from v2.0.0 land on the feature summary first. Diagnostic page intro paragraphs on every Tier 1 tab.

### 📦 New files

- `src/Services/Events/MoonExtractionEventPublisher.php` — EventBus publisher
- `src/Console/Commands/ScanMoonExtractionEventsCommand.php` — scanner cron
- `src/Console/Commands/ScanMetenoxCargoFillCommand.php` — Metenox bay-fill scanner cron
- `src/database/migrations/2026_01_01_000016_create_moon_extraction_event_log_table.php` — per-extraction dedup latch
- `src/Database/migrations/2026_01_01_000017_add_metenox_cargo_full_notification.php` — `notify_metenox_cargo_full` column + `metenox_cargo_alert_state` dedup table
- `src/Services/DiscordRoleResolver.php` — role-source detector + lookup map
- `src/Services/Moon/MetenoxCargoService.php` — Metenox cargo reader + PluginBridge backing + fill % math
- `src/Resources/views/moon/metenox-cargo.blade.php` — director-only Metenox page
- `src/Resources/views/settings/partials/_routing_map.blade.php` — routing-map partial
- `src/Resources/views/settings/partials/_role_pill.blade.php` — resolved-role pill
- `src/Resources/assets/js/eve-time.js` — EVE → local tooltip / pill converter
- `src/Resources/assets/js/eve-countdown.js` — live-tick countdown widget

### ⚙️ Modified surfaces

- `MoonController` — new `metenoxCargo()` action gated on `mining-manager.director`
- `MiningManagerServiceProvider` — registers `ScanMoonExtractionEventsCommand` + new PluginBridge capability `mining.metenox.cargoSnapshot`
- `Database/Seeders/ScheduleSeeder` — wires the scanner cron (firstOrCreate)
- `Http/routes.php` — `/mining-manager/moon/metenox-cargo` placed before the `/{id}` catch-all
- `Config/Menu/package.sidebar.php` + `Resources/lang/en/menu.php` — Metenox Cargo sidebar entry
- `Resources/views/settings/{sidebar,index}.blade.php` — Routing Map tab
- `Resources/views/diagnostic/index.blade.php` — Health Checks renamed + default + 6 Tier-1 intro boxes
- ~10 events/* and moon/* blades — `.eve-time` + `.eve-countdown` wrap on every absolute time + live countdown surface
- `Resources/views/moon/{show,active,extractions,index}.blade.php` + `analytics/partials/moon-extraction.blade.php` + `settings/tabs/webhooks.blade.php` — 18 inline jackpot styles converted to `.mm-jackpot*` classes
- `Resources/views/help/index.blade.php` — three new sections (What's New, Time display, Metenox cargo)
- `Resources/assets/css/mining-manager-dashboard.css` — `.mm-jackpot*` + `.eve-countdown-*` + `.eve-time-local` + `.diag-tab-intro` primitives

### 🎯 Manager Core pricing centralization (added 2026-05-28)

Late addition to v2.0.1 closing the **"MC config lives in two places"** gap that v2.0.0 left behind. Single source of truth for which market + price type Mining Manager reads from is now Manager Core's `manager_core_pricing_preferences` table — operator changes in MC's UI propagate to MM's actual price reads within one cache flush cycle, not on the 4-hour scheduled refresh boundary.

**Pricing settings tab rewrite.** When `provider=manager-core` is selected, the "Manager Core Configuration" panel is now a **read-only status readout** pulling MM's current preference from MC via the new `pricing.getPreferenceForPlugin` bridge capability (Market / Price Type / Provider routing / admin-overridden flag), plus a prominent **"Configure pricing in Manager Core →"** deep-link button resolved via `pricing.preferencesUrl`. The Variant dropdown (min/max/avg/median/percentile) is gone entirely — only variant=min produces meaningful tax + payout values (lowest sell = real buy price for an instant market buy), now hardcoded in `CachePriceDataCommand`. The page-level Price Type dropdown is hidden via JS when `provider=manager-core` (MC's pref owns it).

**Boot-time preference seeding.** `MiningManagerServiceProvider::registerCrossPluginPricingSubscription` now also calls `pricing.registerPreference('mining-manager', $market, $priceType, ...)` when MC is the configured provider. Idempotent on MC side via the `admin_overridden` flag — operator edits in MC's UI are never trampled by this boot call. Same call fires on save-path via `SettingsController::updatePricing` so first save populates MC's table.

**Live cache invalidation via EventBus.** New `PricingPreferenceChangedHandler` (in `src/Services/Pricing/`) subscribes to MC's new `pricing.preference_changed` topic via `registerPricingPreferenceSubscription` boot method. Filters payload for `plugin_key='mining-manager'` (no-op for other plugins) and flushes `mining-manager:prices` + `mining-manager:moon-values` cache tags so the next read goes fresh through the bridge. Subscribed UNCONDITIONALLY when MC is installed — handler filters internally so a later operator switch to MC works without container restart.

**Centralized market resolver.** New `SettingsManagerService::resolveMcMarket()` is the single point of bridge integration for "where does MM look up its MC market?". Calls `pricing.getPreferenceForPlugin` once per request (static method-var cache) and falls back to `'jita'` literal on bridge-call failure. `getPricingSettings()['manager_core_market']` now transparently returns this value, so every downstream caller (`PriceProviderService`, `CachePriceDataCommand`, `DiagnosticController`, `MasterTestRunner`) sees MC's authoritative market without code changes. Closes a real consistency gap from earlier work where the cache populate honored MC's pref but the LIVE read path still used the stale local default.

**Jita fallback now actually works on the MC path.** Was effectively dead code before — `$currentMarket` always equalled `'jita'` from the stale local default, so the `if ($currentMarket === 'jita') return $prices;` early-return at `PriceProviderService.php:711` was always hit. With the new resolver returning MC's actual market, items that come back as 0 from a non-Jita market correctly retry through Jita as a per-item safety net.

**Dead local state cleanup.** Migration `2026_01_01_000018_drop_unused_mc_pricing_settings` deletes `pricing.manager_core_market` + `pricing.manager_core_variant` rows from `mining_manager_settings`. `SettingsController::updatePricing` stops writing them. Both were dropped from the UI in this same v2.0.1 cycle and the only remaining reader was switched to MC's bridge.

**Bridge version-check call removed.** `bridge.requireMinimumVersion('1.5.0')` had no signal — MC starts at 1.0.0, no older MC version exists, so the version comparison always passed. The `class_exists` guard at the top of `registerCrossPluginStructureAlerts` is the actual "is MC available?" gate. MC keeps the capability registered for any future major-rework scenario; MM just doesn't call it anymore.

**Per-plugin provider override consumer (added 2026-05-29).** Companion to MC's Option B work — Mining Manager now passes its plugin key (`'mining-manager'`) as the optional 4th arg to `pricing.getPrices` on every MC bridge call. When the operator sets a `provider_override` on Mining Manager's row in MC's Pricing Preferences page (e.g. routing MM through Janice for Jita while Structure Manager continues through Fuzzwork for the same Jita), MC consults the override and does a live upstream fetch through that provider instead of reading its local cache. Three call sites updated:
- `CachePriceDataCommand::syncFromManagerCore` (scheduled cache refresh, every 4h)
- `PriceProviderService::getPricesFromManagerCore` (live read path used by moon-value / tax / payout)
- `PriceProviderService::applyJitaFallback` (per-item Jita retry — uses the same override so the fallback is consistent with the primary read)

The MC Configuration status panel on Settings → Pricing also grows two new badges showing "per-plugin override" vs "market default (provider)" so the operator can see at a glance which routing is in effect. Reads the new `provider_override` + `market_provider` fields from MC's `pricing.getPreferenceForPlugin` response, with a defensive fall-through to the existing display if those fields are ever absent from MC's payload.

**Cache impact when override is set:** every MM cache refresh hits the override provider live (one batch upstream call per refresh cycle, every 4 hours via the scheduled cron). Acceptable bandwidth even with strict-rate-limit providers like Janice. When no override is set, MC reads its local cache (current behavior, unchanged).

**Operator workflow:** MC → Settings (set Janice API key) → MC → Pricing Preferences → find Mining Manager row → change "Provider Override" dropdown from "Use market's provider (Fuzzwork)" to "Janice" → Save → MC publishes `pricing.preference_changed` → MM's handler flushes the local cache → next cache refresh fetches through Janice. End-to-end self-serving — no MM-side restart required.

**Files added in this section:**
- `Services/Pricing/PricingPreferenceChangedHandler.php` (new, ~90 lines)
- `Database/migrations/2026_01_01_000018_drop_unused_mc_pricing_settings.php`

**Files modified in this section:**
- `Resources/views/settings/tabs/pricing.blade.php` — MC Configuration panel rewrite
- `Http/Controllers/SettingsController.php` — drops variant/market writes; new `pricing.registerPreference` call on save
- `MiningManagerServiceProvider.php` — new `registerPricingPreferenceSubscription` method; boot adds `pricing.registerPreference` call; `bridge.requireMinimumVersion` call removed
- `Services/Configuration/SettingsManagerService.php` — new `resolveMcMarket()` helper; `getPricingSettings()['manager_core_market']` now consults MC via bridge
- `Console/Commands/CachePriceDataCommand.php` — variant hardcoded to 'min'; market read via `getPricingSettings()`
- `Http/Controllers/DiagnosticController.php` — uses `getPricingSettings()` market resolver

**Requires Manager Core v1.0.0** (Manager Core's first stable release) with the new pricing capabilities (`pricing.getPreferenceForPlugin`, `pricing.preferencesUrl`, and the `pricing.preference_changed` topic published from `PricingPreferencesController`). Defensive try/catch on every bridge call — `resolveMcMarket()` falls back to `'jita'` literal if the capability call ever fails, so the Help page and pricing reads never error out.

### ⚠️ Compatibility

- **Fully additive.** No existing schema changes, no released migration touched, no public API change, no breaking setting changes.
- New `moon_extraction_event_log` table created on plugin boot via migration `000016`. Additive.
- New `metenox_cargo_alert_state` table + column on `mining_manager_settings` via migration `000017`. Additive.
- Migration `000018` deletes two now-unused settings rows (`pricing.manager_core_market`, `pricing.manager_core_variant`) — was operator-configurable in the UI in v2.0.0 but those dropdowns are gone in v2.0.1. Forward-only, idempotent.
- New schedule rows added once via `ScheduleSeeder` (`firstOrCreate` semantics — existing cron customisations preserved).
- Without Manager Core installed, the extraction scanner is a no-op and nothing changes from v2.0.0 behaviour. MM falls back to its own provider stack (SeAT / Fuzzwork / Janice).
- Metenox Cargo page is gated by `mining-manager.director`. No backfill required.
- Routing Map is read-only.
- Subscribers of the extraction events honour visibility scoping via the `corporation_id` field on each event payload.
- The MC pricing centralization is **transparent to existing installs** — first boot after upgrade calls `pricing.registerPreference` to seed MC's row from MM's existing settings; subsequent operator changes flow through MC's UI. Pre-existing operator MC-side preferences (if any) are preserved via the `admin_overridden` flag.
- **Zero impact on the rest of the plugin** — tax calculation, extraction tracking, theft detection, jackpot detection all unchanged.

---

## [2.0.0] — 2026-05-03 — The Ecosystem Era

Mining Manager becomes ecosystem-aware. It still works standalone, but when **Manager Core** is installed it uses centralised pricing via the PluginBridge contract, and when **Structure Manager** is installed it subscribes to structure-threat events and dispatches `extraction_at_risk` / `extraction_lost` alerts. Both integrations are optional; existing v1.0.3 installs upgrade cleanly.

### 🎉 Headline features

- **Cross-plugin alerts (MC + SM)** — `extraction_at_risk` (fuel critical, shield/armor/hull reinforced) and `extraction_lost` (refinery destroyed) notifications via Discord/Slack/Custom/EVE Mail. Includes attacker info, system security, fuel/timer details, severity-aware embed colors, and a one-click Structure Board deeplink to SM. Toggles auto-disable when either MC or SM is missing.
- **Master Test diagnostic** — one-click read-only smoke chain on the new default Diagnostic tab. ~26 checks across schema integrity, settings consistency, cross-plugin integration, pricing path, notifications, lifecycle, tax pipeline, and security hardening. Sub-30-second runtime. Pass/warn/fail/skip table with category badges + "Show only issues" filter.
- **Auto-match wallet payments toggle** — Settings → General → Payment Settings checkbox. ON (default) = listener applies matched payments automatically; OFF = matches detected and listed on Wallet Verification but require manual confirmation. Useful for installs wanting a human-review step.
- **Manager Core pricing integration** — when MC is the configured provider, MM consumes prices via `pricing.getPrices` capability. Boot-time idempotent re-subscribe with signature-cache fast path; staleness check on served prices (8h threshold) with structured warning logs.
- **Notification surface filled in** — `formatMessageForESI` now covers all 19 notification types (was ~60% pre-v2.0.0). EVE Mail recipients now get readable subjects + bodies for theft, jackpot, structure alerts, reports, etc., not raw JSON dumps.

### 🔄 Architectural changes

- **Three audit cycles** (cycle 1 hardening, cycle 2 cross-plugin contract drift, cycle 3 full audit Tier 1+2+3) shipped before this release. ~50 numbered findings across CRITICAL/HIGH/MEDIUM/LOW, all fixed.
- **Atomic compare-and-swap pattern** standardised across 4 race-prone sites: `StructureAlertHandler` dedup latches, `MoonController::reportJackpot`, `MoonExtractionService::sendMoonArrivalNotification`, `EventManagementService::updateEventStatuses` + manual paths. Same pattern across all 3 wallet-payment dispatch sites: `applyPayment`, `ProcessWalletJournalListener::handle`, `autoVerifyFromCorporationWallet`.
- **PluginBridge contract** fully respected — every cross-plugin operation (read, subscribe, unsubscribe, getPrice, getPrices, getTrend) routes through the documented capability surface. Direct `DB::table('manager_core_*')` queries eliminated.
- **Forward-only migration discipline** — 5 new migrations (000011-000015) for tax-code uniqueness, period_start backfill, orphan settings cleanup, discord_avatar_url add+drop. Migration 000001 untouched. Released-migration immutability rule honored.

### ⚠️ Compatibility notes

- **No breaking changes for standalone installs.** The plugin still works without Manager Core or Structure Manager — the cross-plugin features simply remain disabled and the relevant webhook toggles auto-grey-out in the UI.
- **`discord_avatar_url`** removed end-to-end. The field never worked correctly (was a duplicate of Discord's webhook UI avatar setting). Operators who tried to use it never got the override they expected; no behavior change in production. Forward-only migration 000015 drops the column.
- **`slack_webhook_url`** now requires `https://`. Slack webhooks have always been HTTPS-only at `hooks.slack.com`, so any existing valid value passes the new rule.
- **`ScheduleSeeder`** reverted to canonical SeAT v5 `firstOrCreate` semantics. The override that rewrote operator cron customizations on every plugin boot is gone. Existing installs keep whatever's currently in their `schedules` table (no behavior change on first upgrade).

### 🚀 What's next

- Pings plugin to subscribe to MM's events (Phase 3 of SM v2 roadmap, applies to MM too)
- Per-corp event webhook scoping (P6, deferred from v1.0.x cycles)
- EVE Mail channel for tax invoices to miners not in SeAT (planned for v2.1.0)

---

## Pre-2.0.0

A large audit and polish pass landed before v2.0.0 and rolled straight into it (no separate tag). The notification system was consolidated into a single dispatcher, the tax-lifecycle notifications (overdue, invoice) were fixed to actually fire, and robustness was hardened across the scheduled commands (locks everywhere, several race conditions closed). See the git history for the per-file detail.

## [1.0.3] - Event Accuracy, Period Awareness, Weekly Removal

Big release. Three parallel streams of work converged:

1. **Event tracking rebuilt** from the ground up. A new `event_mining_records` table materialises the exact mining activity qualifying for each event with all four scope filters (corp, location, time, ore category) applied at populate time. Tax attribution is now per-row — the modifier applies only to the actual event-window slice, not the whole day's mining. ISK saved during events is surfaced to miners on their pages and to directors on the dashboard.
2. **Bi-weekly tax period support matured.** The data layer already supported it; presentation and queries now do too. Period switches queue to a safe cutover date to prevent row collisions.
3. **Weekly tax period removed.** ISO weeks don't align to calendar months; the straddling weeks caused double-tax and chart aggregation problems. Biweekly covers the sub-monthly use case cleanly.

### Added

**Event System Refactor (Phases 1–3)**
- New `event_mining_records` table (migration `2026_01_01_000005`) — canonical record of which mining qualifies for each event. Populated by `EventMiningAggregator` with all filters baked in (corp + location + time + ore category). Moon events read `mining_ledger` (day-level observer data); belt/ice/gas events read SeAT's `character_minings` with datetime precision via `time` column.
- New `event_discount_total` column on `mining_ledger_daily_summaries` (migration `2026_01_01_000006`) — daily sum of ISK waived by event modifiers.
- New per-ore entries in `ore_types` JSON: `event_id`, `event_qualified_value`, `event_discount_amount`, blended `effective_rate`.
- New `mining-manager:backfill-event-records` artisan command — `--event=ID` / `--status=active|completed|planned` / `--fresh` for one-off rebuild after deploy.
- New `EventMiningAggregator` service — lazy-promoted via `MiningEvent::booted()` hook when any scope field (`type`, `corporation_id`, `solar_system_id`, `location_scope`, `start_time`, `end_time`) changes on save.
- `LedgerSummaryService::getEventAttributionForLedgerRow()` — per-row tax attribution. Modifier applies to the exact slice of mining that overlapped the event window, not to the whole day.
- Historical pricing preservation for non-moon events via proportional allocation from `mining_ledger.total_value` — backfilling an old event no longer rewrites ISK with today's prices.
- Event form **tax-compatibility panel** — badge row showing currently-taxed categories, reactive status block (🟢 full / 🟡 partial / 🔴 empty) based on the chosen event type, and a suggested event types list. Prevents running a "gas_huffing" event on an install that isn't taxing gas.
- Miner-facing event discount indicators:
  - **My Mining** — green callout "Event Discount Applied: you saved X ISK this period" + new orange small-box "Total Event Savings (All Time)" showing the running ISK total of tax waived from event participation across every event the user's characters have ever joined
  - **My Taxes** — top banner "Event discount applied this period: X ISK saved", plus per-ore "saved Y ISK" sub-line in the breakdown table
  - **My Events** — new full-width banner "Total tax saved from event participation: X ISK" near the top, plus a "Your tax saved: X ISK" line on every event card (active + completed sections)
  - **Ledger Summary** (director) — "incl. −X ISK event discount" under the Total Tax info-box
  - **Calculate Taxes** (admin) — Event Tax column now shows the real per-row discount (previously always 0 — column read a non-existent `event_tax_amount`)
  - **Director Dashboard charts** (Mining Tax, Event Tax) and **Member Dashboard** (Mining Income Last 12 Months) — gained a period-aware footnote under each chart when the install runs biweekly, clarifying that biweekly periods within each calendar month are summed into that month's bar.

**Savings-attribution helpers**
- `LedgerSummaryService::getTotalEventSavings($characterIds, $start = null, $end = null)` — fast sum of `mining_ledger_daily_summaries.event_discount_total` for a character set over an optional date range.
- `LedgerSummaryService::getEventSavingsByEvent($characterIds, $start = null, $end = null)` — walks `ore_types` JSON and returns `[event_id => ISK saved]` for per-event attribution (used by the My Events per-card line).

**Period Awareness (biweekly/weekly presentation)**
- `TaxController::myTaxes` resolves the configured period, queries by `period_start` (exact), falls back to oldest unpaid tax when the current period hasn't been invoiced yet, exposes all unpaid taxes to the view.
- My Taxes page uses period-aware labels everywhere — Current Balance card, Mining Breakdown header, Event Discount banner, "no tax this period" alert.
- New **"All Unpaid Periods" table** on My Taxes when more than one tax is outstanding — shows every period with amount, due date, status badge, details link.
- `TaxController::index` (director Tax Overview) shows period context in the summary header. On non-monthly setups: "Current Biweekly period: Apr 15-30, 2026" + an additional sub-line under "Collected" showing ISK attributable to the current active period specifically (vs. the existing calendar-month total).
- `TaxController::myTaxBreakdown` AJAX returns period-bound slice (`period_type` / `period_start` / `period_end` / `period_label` in response; legacy `month` key kept for backward compat).
- `getMyTaxBreakdownData` signature widened to `(array $characterIds, Carbon $start, Carbon $end)` — mining breakdown aligns with displayed period instead of calendar month.

**Period Switch Safeguard**
- New settings slots: `tax_rates.tax_calculation_period_pending` and `tax_rates.tax_calculation_period_effective_from`. Period-type changes queue instead of applying immediately — unless the admin checks a new "Apply immediately" override (intended for fresh installs).
- Effective date defaults to **day 3 of next month** for monthly/biweekly — lets the current scheme's day-2 previous-period calc complete before promotion. Prevents H2 data loss on biweekly → monthly switches.
- `TaxPeriodHelper::getPendingPeriodChange()` exposes the queued change; new partial `taxes/partials/_pending_period_switch_banner.blade.php` shows a yellow warning on every tax page while a switch is queued.
- Lazy promotion in `getConfiguredPeriodType()` — no cron needed; first tax-page load or calculate-taxes run on or after the effective date auto-promotes and logs the transition.

**Cleanups / Observability**
- `Cache::lock()` added to `mining-manager:generate-reports` and `mining-manager:update-extractions` (matches the 8 other commands that already had it).
- `Http::timeout(10)` added to the three previously-bare HTTP calls (Slack webhook + two Fuzzwork price GETs).
- Security badge on analytics systems table now uses 0.45 (CCP's actual high-sec threshold) instead of 0.5 — Tasabeshi et al. now correctly green.
- Log line on period promotion: `Mining Manager: Tax calculation period promoted biweekly → monthly (effective 2026-05-03, promoted on 2026-05-03)`.

### Fixed

- **Event tracking only counted 1 of 19 participants** — `EventTrackingService::updateEventTracking()` was comparing a DATE column against DateTime watermarks, silently excluding all rows after the first tick. Also dropped the self-defeating `last_updated` incremental watermark; method is now idempotent and runs the full event window every pass via `updateOrCreate`.
- **Events showed "Total Mined: 0 ISK"** — `event_participants.value_mined` column added (migration `2026_01_01_000004`) alongside the existing `quantity_mined`. Event Tracking Service now populates both from `mining_ledger.total_value`.
- **Event type didn't scope tax modifier to correct ore category** — added `EVENT_TYPE_ORE_CATEGORIES` constant on `MiningEvent` + `appliesToOreCategory()` helper. `mining_op` applies only to regular ore, `ice_mining` only to ice, etc. "Special Event" covers every currently-taxed category.
- **`character_infos.corporation_id` reads returned null** — latent bug since SeAT's 2019 schema change dropped that column in favor of `character_affiliations`. Fixed in `LedgerSummaryService::generateDailySummary` (was silently breaking guest-mining detection and corp-scoped event attribution), `EventMiningAggregator`, `MiningTax::getCorporationIdAttribute`, and `CharacterInfoService`.
- **Event charts displayed garbage (~92K ISK for a 3.87B event)** — three director/member dashboard charts computed event tax as `$event->total_mined × hardcoded 10% × modifier`, but `total_mined` is unit quantity not ISK. All three now read from the authoritative `event_discount_total` on daily summaries:
  - Director "Event Tax (12 Months)" chart
  - Member "Mining Income" chart `event_bonus` series
  - Events index "Total Value" KPI
  - My Events "Total Mined" / "Avg Per Event" stats
- **my-events.blade.php crashed with "Attempt to read property 'id' on null"** — the view used `auth()->user()->id` (SeAT user ID) as a character_id filter, so `$myParticipation` was usually null. Refactored to aggregate across all of the user's characters via `$characterIds` passed from the controller; rank computed across top participants by `character_id` match rather than a brittle `$p->id === $myParticipation->id`.
- **Events list showed "0 participants"** — `events/index.blade.php` used `$event->participants_count` (plural typo); column is `participant_count` (singular). Dropped the bogus `/ max_participants` suffix too (that column doesn't exist).
- **Retroactive daily-summary rebuilds showed zero event discount** — `getEventAttributionForLedgerRow` filtered candidate events to `status='active'` only, so rebuilding a past day's summary after the event had transitioned to `completed` found nothing. Now accepts `active` and `completed` (excludes `planned` and `cancelled`).
- **`event_discount_total` was zero for moon events — the actual root cause.** `LedgerSummaryService::getOreCategory()` returned generic strings (`'moon_ore'`, `'abyssal_ore'`, `'triglavian_ore'`) but `MiningEvent::EVENT_TYPE_ORE_CATEGORIES` (and `mining_ledger.ore_category`) use the specific-rarity values (`'moon_r4'`, `'moon_r8'`, ..., `'moon_r64'`, `'abyssal'`, `'triglavian'`). The ingestion commands (`ProcessMiningLedgerCommand`, `ImportCharacterMiningCommand`) already produced the correct specific values; only this view-layer helper drifted. Result: `MiningEvent::appliesToOreCategory('moon_ore')` always returned false, attribution lookup always returned null, and every daily summary had `event_discount_total = 0` regardless of event activity. Aligned the helper with the ingestion side. Verified on user's install: 31 daily summary rows now carry non-zero discounts totaling ~38.5M ISK across 19 miners for a single 48h moon event.
- **Calculate Taxes Event Tax column always showed 0** — the attribution prefetch map keyed on `$row->mining_date` via `sprintf '%s'`, but `EventMiningRecord` casts `mining_date` to Carbon (`'date'` cast). Carbon's `__toString()` emits `"YYYY-MM-DD HH:MM:SS"` while the entry-side lookup used `Carbon::parse($entry->date)->toDateString()` → `"YYYY-MM-DD"`. Keys never matched. Explicit `->format('Y-m-d')` on both sides now.
- **Wrongly-named migration file** — `2026_04_21_000001_add_value_tracking_to_events` renamed to `2026_01_01_000004_add_value_tracking_to_events` to match plugin's fixed-date-prefix + sequential-numbering convention. Also converted from anonymous class to named class (`AddValueTrackingToEvents`) matching the other 3 migrations.
- **Dead code paths removed**:
  - `ProcessMiningLedgerListener` (deprecated, never registered, never fired in SeAT v5)
  - Dead `character_infos.corporation_id` fallback branches in `MiningTax` + `CharacterInfoService` (column dropped in 2019, branches unreachable)

### Changed

- **Weekly tax calculation period removed.**

  *Why:* ISO weeks (Mon-Sun) don't align to calendar months. A week starting Apr 27 ends May 3, so the tax row covered mining from April 27-30 AND May 1-3. Three compounding problems followed: (1) straddling tax rows leaked accounting into the next month; (2) switching weekly → anything caused **double-tax** because the straddling row's May days overlapped with the first new-scheme row also covering May; (3) dashboard charts had to smear weekly row totals across adjacent months. Biweekly (1st-14th, 15th-end) covers the sub-monthly use case cleanly.

  *If your install was running weekly — what happens on upgrade:*
  1. **Auto-heal on first read.** The first tax-page load or `calculate-taxes` cron after the upgrade rewrites `tax_rates.tax_calculation_period` from `weekly` to `monthly` in the settings store, logging a warning: `Mining Manager: Auto-migrated tax_calculation_period from deprecated "weekly" to "monthly"...`. No admin action required.
  2. **Historical weekly rows preserved.** Existing `mining_taxes` rows with `period_type='weekly'` stay in the database forever. They remain visible in Tax History, Tax Details, My Taxes breakdown, and CSV exports — rendered with their original weekly labels (e.g. "Mar 3-9, 2026") via `MiningTax::formatted_period`.
  3. **No new weekly rows.** Going forward the plugin only writes `monthly` or `biweekly` rows.
  4. **Switching to biweekly** (if the admin prefers sub-monthly over monthly): open Settings → Tax Rates and change the dropdown. The change queues to day 3 of next month via the new period-switch safeguard (no collision with the auto-migrated monthly setting).

  *Defense in depth:* three layers of weekly coercion ensure no new weekly data can slip in:
  - Settings form validation rejects `weekly` (`in:monthly,biweekly`)
  - `SettingsManagerService::updateTaxRates` coerces `weekly` → `monthly` with a log warning if any caller bypasses the form
  - `TaxPeriodHelper::normaliseLegacyWeekly()` coerces `weekly` passed to internal methods (period bounds, calc-day checks, etc.)

  No schema migration required. `mining_taxes.period_type` is `string(20)`, not an enum.
- **Moon event corp filter semantics** now documented and uniform:
  - Miner's current corp must equal `event.corporation_id` for corp-scoped events (no miner-corp filter for global events)
  - Observer row corp (moon owner) is NOT required to match miner corp — a Corp-B miner at a Corp-A moon legitimately counts for a Corp-B event and for any global event, provided the moon row is in the source pool per `tax_selector`
  - Source pool for moon events follows `tax_selector`: `only_corp_moon_ore` → restrict to moon-owner corp's observers; `all_moon_ore` → any observer; `no_moon_ore` → no observer data
- **Non-moon event participation narrowed by `tax_selector`** — a gas event on an install with `tax_selector.gas=false` now produces no records (previously it silently tracked zero-tax activity). Event form warns on mismatch.
- **Event webhook includes ISK value mined** — previously moon-event notifications showed only quantity; now also reports ISK total where available.

### Known Limitations

- **Moon events stay day-level.** EVE's observer data is day-aggregated; the plugin cannot get sub-day precision on moon mining until CCP changes ESI. Documented in Help under Events → Time Granularity.
- **Non-moon events use SeAT fetch time**, not literal EVE mining time (character_minings doesn't carry the moment of mining). Good enough for events spanning several hours; noisy for sub-hour events.
- **Weekly removal does not delete historical rows.** They remain visible in Tax History and export reports forever (we don't touch released migrations).

### How it works

**ESI tells us WHAT is happening. The clock tells us WHEN to notify. `event_mining_records` tells us WHICH mining counts for which event.**

## [1.0.2] - Time-Based Moon Arrival Notifications

### Fixed
- **Moon arrival notifications silently missed when chunk arrived between cron ticks** -- The previous ESI-polling-based notification path was fragile. The import loop's `determineStatus()` would write `status='ready'` directly when `chunk_arrival_time` had passed, bypassing the transition-detection code that fired notifications. If ESI was stale, offline, or the cron timing was off, notifications were lost. Now decoupled from ESI entirely.

### Added
- **`mining-manager:check-extraction-arrivals` command** -- New lightweight cron running every minute. Pure time arithmetic, no ESI calls. Queries extractions whose stored `chunk_arrival_time` has passed and fires moon_arrival notifications. Idempotent via the `notification_sent` flag. Handles edge cases:
  - Arrivals between 2h ESI-poll ticks (fire within 60s of actual arrival)
  - ESI downtime (stored `chunk_arrival_time` is the source of truth)
  - Extractions imported directly as `'ready'` (notification catches up automatically)
  - Cron outages (backed-up arrivals fire when cron resumes)
- **`mining-manager:backfill-extraction-history` command** -- Reconstructs historical `moon_extraction_history` rows from EVE `MoonminingExtractionStarted` character notifications. When the plugin is installed on a corp that has months of pre-existing mining history, ESI only returns active/upcoming extractions; completed cycles can't be re-fetched. SeAT retains character notifications, though, so this command scans them, dedupes by `(structure_id, readyTime)`, matches each extraction to its corresponding fracture/cancel notification (manual via `MoonminingLaserFired`, auto via `MoonminingAutomaticFracture`, or `MoonminingExtractionCancelled`), computes actual mined values from `mining_ledger` where data exists, and inserts complete history rows. **Progress bars** for both the dedup pass (parsing YAML notifications) and the main processing pass (DB queries per extraction). Supports `--structure=ID` to scope to one structure, `--days=N` lookback window, `--dry-run` preview, and `--force` to recreate existing rows. Historical ISK prices are unknown so `estimated_value` fields are left NULL. Automatically invoked during `mining-manager:initialize` Phase 3 (historical backfill) when the user opts in.
- **Cancellation detection via EVE notifications** -- New `detectCancellations()` method on `MoonExtractionService` parses `MoonminingExtractionCancelled` character notifications (same pattern as existing fracture detection). When a director cancels an extraction in-game, the state system marks it `cancelled` within the next 2h poll cycle. The notification watchdog then skips it -- no false "Moon Chunk Ready" alert fires at the originally scheduled arrival time. `cancelled` is now a valid status alongside `extracting`, `ready`, `expired`. Runs automatically inside `update-extractions`. Follows the existing notification-parsing convention (`MoonminingLaserFired`, `MoonminingAutomaticFracture`, `MoonminingExtractionStarted`).
- **Architecture documentation** -- README and in-app Help docs now explain the two-system model:
  - State system (ESI-driven, every 2h): what EVE says is happening
  - Notification system (time-driven, every minute): when to notify
- **`--dry-run`, `--hours-back`, `--limit` flags** on the new command for testing and controlling dispatch volume.
- **Enhanced diagnostic logging** -- `updateExtractionStatuses()`, `sendMoonArrivalNotification()`, `sendMoonNotification()`, and `getMoonOwnerScopedWebhooks()` now emit structured `Log::info`/`Log::warning` entries at every decision point. Visible in SeAT Log Viewer (filter by Info level). Makes silent failures easy to diagnose.

### Changed
- **`sendMoonArrivalNotification()` now sets `notification_sent = true`** after successful dispatch, enforcing dedup across both entry points (old `updateExtractionStatuses` path and new `check-extraction-arrivals` cron). First caller wins, subsequent callers skip safely.
- **Archive command now archives cancelled extractions** -- previously `ArchiveOldExtractionsCommand` only archived `expired` and `fractured` statuses. Cancelled extractions (detected via `MoonminingExtractionCancelled` notification) accumulated in `moon_extractions` indefinitely because their originally planned `natural_decay_time` stayed in the future. Now handled via an OR branch: cancelled rows are archived 7 days after `updated_at` (the cancellation detection timestamp). Ensures `moon_extraction_history` is the single source of truth for past extractions regardless of final state.
- **Cancelled extractions display with a semantic badge** -- Moon show page now renders cancelled extractions with a dark badge and ban icon (`<i class="fas fa-ban">`) rather than falling through to the generic "warning" label.
- **Backfill command now correctly treats cancelled extractions as having zero mining** -- cancelled extractions never had a chunk to mine. If ledger activity exists in the cancelled extraction's time window, it belongs to a different (typically rescheduled) extraction. The backfill now sets `actual_mined_value`, `total_miners`, and `completion_percentage` to zero for cancelled rows regardless of what the ledger shows.
- **Moon show page history now unions both tables** -- previously the controller checked `moon_extraction_history` first and only fell back to `moon_extractions` if history was empty. Once any archived row existed, recently-expired extractions (still in `moon_extractions`, pending their 7-day archive cooldown) became invisible. The controller now queries both tables, dedupes by `chunk_arrival_time`, and merges into a single sorted list. Recently-terminal extractions appear immediately without waiting for archival. The 7-day archive cooldown is kept as-is to allow late ESI fracture data to settle.
- **"Value at Arrival" now correctly preserved and displayed** -- The `estimated_value_pre_arrival` column on `moon_extractions` (→ `estimated_value_at_arrival` on `moon_extraction_history`) was being overwritten every 12 hours by `RecalculateExtractionValuesCommand`, defeating its purpose as a historical snapshot. Three fixes:
  1. `CheckExtractionArrivalsCommand` (every-minute cron) now snapshots the current `estimated_value` into `estimated_value_pre_arrival` at the moment the chunk arrives — one-time, idempotent, only runs if the field is NULL. This locks in the arrival-time price ~60s after actual arrival.
  2. `RecalculateExtractionValuesCommand` now only updates `estimated_value_pre_arrival` for extractions whose `chunk_arrival_time` is still in the future. Once arrived, the snapshot is frozen.
  3. Moon show page history table column renamed from "Estimated Value" to "Value at Arrival" and now reads `estimated_value_at_arrival` (archive) / `estimated_value_pre_arrival` (pending archive) with fallback to `final_estimated_value` or N/A.
- **Completion % baseline fixed** -- was calculated against `estimated_value` (current running value, drifts with market) — now uses `estimated_value_pre_arrival` (locked at arrival) for historically accurate completion measurements. The chunk had a specific ISK value when it arrived; completion % now measures what fraction of THAT value was captured before despawn. Falls back to `estimated_value` for rows without arrival snapshots.
- **Fixed narrow mining window + cancelled-attribution bug in `calculateActualMined` helpers** -- three separate copies of this helper (backfill command, moon show controller, archive command) all had the same two issues: (1) searched only the 3-hour pre-fracture window instead of the full 72-hour mining lifecycle, missing most actual mining activity, (2) counted ledger activity for cancelled extractions as if they had been mined. All three now use a 72h window from `chunk_arrival_time`, query by `observer_id = structure_id` for precise attribution, and return zeros for cancelled rows.
- **Past Extractions (Archived) table — interactive DataTables + MOC scoping + Structure column** -- the archived history table on `/mining-manager/moon` is now filtered to Moon Owner Corporation only (other directors' private moons on shared SeAT installs no longer leak through). Added a Structure column showing station/refinery names (batch-loaded via `MoonExtraction::loadDisplayNames()` — no N+1). Table uses jQuery DataTables for client-side sorting (all columns, with numeric `data-order` attributes on dates/values/progress bars for correct sort semantics), full-text search across all columns, a Status filter dropdown (auto-populated from the visible badge text), and pagination (10/25/50/100/All). Default sort is chunk arrival descending.
- **`mining-manager:backfill-extraction-history` now filters by Moon Owner Corporation** -- resolves MOC from settings, pre-loads the set of structure IDs owned by that corp, and skips notifications for any other structure during the dedup pass. Rejects `--structure=ID` for structures not owned by MOC. Reports a count of skipped foreign-corp notifications. Fully dynamic — if MOC changes in Settings, next run uses the new value.

### How it works
**ESI tells us WHAT is happening. The clock tells us WHEN to notify.**

## [1.0.1] - Notification & Event Fixes

### Fixed
- **Ghost webhook / duplicate report notifications** -- Monthly report cron ran daily instead of monthly, generating identical reports every day and dispatching to all webhooks. Changed to day 9 of month (7 days after finalize-month for collection % to mature). Added dedup guard with `--force` override.
- **Moon arrival notifications silently never sent** -- Cron command had a duplicate status-transition method that bypassed the notification dispatcher. Extractions transitioned to "ready" but no Discord/Slack notification ever fired. Now delegates to the service's method which includes notification dispatch.
- **Events stuck in PLANNED status** -- No automatic status transitions existed. Events never moved from planned to active to completed unless manually clicked. Added auto-transition logic to the cron with event_started and event_completed notification dispatch.
- **Event location scope broken for constellation/region** -- Constellation and region-scoped events silently failed because the code compared a constellation/region ID directly against solar system IDs. Added spatial hierarchy resolution via mapDenormalize with 24h caching.
- **Role ping ignoring per-type settings** -- Both NotificationService and WebhookService had a legacy fallback that pinged the webhook's discord_role_id even when the per-type "Ping Role" toggle was OFF. Per-type settings are now authoritative in both dispatchers.
- **Manual report dispatch to wrong channel** -- Hidden webhook picker in report generation form silently submitted the first webhook ID. Removed the picker entirely; dispatch is now subscription-driven via webhook configuration.
- **Tax notification scoping** -- Tax notifications via NotificationService were dispatched to all enabled webhooks regardless of corporation. Now scoped to the Moon Owner / Tax Program Corporation, consistent with moon and theft notification scoping.
- **Wallet division showing hangar name** -- Payment instructions displayed hangar division name (e.g. "Handouts") instead of wallet division name (e.g. "Taxes and Bills") because the query didn't filter by `type='wallet'`.
- **Silent event notification failure** -- `sendBroadcast()` checked `general.corporation_id` which was often empty at global scope. Now uses `getTaxProgramCorporationId()` (reads `general.moon_owner_corporation_id`).

### Added
- **Auto tax code generation** -- Tax codes are now automatically generated when invoices are created. The manual `generate-tax-codes` command remains as a fallback.
- **`getTaxProgramCorporationId()` accessor** -- Single canonical method on SettingsManagerService for resolving the tax program / moon owner corporation. All legacy `general.corporation_id` fallback patterns consolidated.
- **`getMoonOwnerScopedWebhooks()` helper** -- Shared webhook filtering for moon, theft, and tax notifications. Ensures webhooks from other directors' corps on the same SeAT install are excluded.
- **Event location resolution on MiningEvent model** -- `getMatchingSystemIds()`, `applyLocationFilter()`, `matchesSystem()` methods resolve constellation/region scopes to system ID lists via mapDenormalize.
- **Audit logging for direct webhook dispatch** -- Moon, theft, and report notifications now log to `mining_notification_log` (previously only tax and event notifications were logged).
- **Report dedup guard** -- `GenerateReportsCommand` skips generation if a report for the same period+type already exists. Use `--force` to override.
- **`--force` flag on generate-reports** -- Allows intentional regeneration of existing reports.

### Changed
- **Event cron frequency** -- `mining-manager:update-events` changed from every 2 hours to every minute for timely status transitions and notifications.
- **Report cron frequency** -- `mining-manager:generate-reports` changed from daily to day 9 of month at 4:05 AM.
- **`generate-tax-codes` default scope** -- Without `--month`, now scans ALL unpaid taxes missing active codes instead of only the previous month.
- **Report "Send to Discord" UI** -- Removed webhook picker from both generate and show pages. Dispatch is now controlled entirely by webhook subscriptions (notify_report_generated flag). Shows informational list of subscribed webhooks.
- **Event notifications scope** -- Event notifications (created/started/completed) remain globally dispatched. All other notification types (moon/theft/tax) are scoped to the Moon Owner Corporation.

## [1.0.0] - Initial Release

### Initial Release

**Core Systems**
- Mining ledger processing with automated price lookups from multiple market data sources (SeAT, Fuzzwork, Janice, Manager Core)
- Daily summaries as single source of truth for all tax calculations
- Per-ore category tax rates (moon R4-R64, regular ore, ice, gas, abyssal, triglavian)
- Multi-corporation support with per-corp tax rates and tax selectors
- Guest mining detection with separate global tax rates (tied to Moon Owner Corporation)
- Event tax modifiers for mining operations (percentage-based discounts/surcharges)
- Tax code generation with wallet payment verification and auto-reconciliation
- Orphan moon ore reconciliation against Moon Owner Corp observer data

**Moon Mining**
- Moon extraction tracking with ore composition and estimated values
- Jackpot detection -- automatic (daily scan of mining data for +100% variant ores)
- Manual jackpot reporting -- members can report jackpots from arrived extractions
- Jackpot verification -- auto-detection verifies manual reports, marks unverified if no data found
- Moon chunk arrival and jackpot Discord/Slack webhook notifications
- Extraction calendar view, active extractions dashboard with auto-refresh
- Moon value calculator/simulator
- Ready-to-fracture and unstable extraction alerts

**Tax System**
- Corporation tax model: Moon Owner Corp observers for moon tax, per-corp rates for configured corps
- Guest miner tax rates in General Settings (global, tied to Moon Owner Corporation)
- 0% guest rate means actual zero tax (not fallback to corp rate)
- Tax calculation from daily summaries (Calculate button) or full regeneration (Recalculate button)
- Payment code generation with configurable prefix
- Tax code mixed-length support (6, 8, 10, or 12 characters) with automatic detection of all active lengths during wallet matching
- Configurable minimum tax amount with exempt/enforce behavior
- Wallet payment verification with tolerance matching and dismissed transaction tracking
- Manual payment entry with two modes: record payment (existing invoices with partial payment support) and manual entry (ad-hoc mid-period settlements for characters leaving corp)
- Tax exemption threshold for small miners
- Tax reminders, invoices, and overdue notifications via Discord/Slack
- Tax announcement notification for all members when new invoices are generated (no ISK amounts, links to My Taxes and How to Pay)

**Mining Events**
- Create mining events with participant tracking and leaderboards
- Tax modifier support (percentage discount/surcharge during events)
- Event lifecycle notifications (created, started, completed)

**Reports & Analytics**
- Daily, weekly, monthly reports with PDF, CSV, and JSON export
- Scheduled report generation with Discord/Slack webhook delivery
- Corporation dashboard with 12-month charts and statistics
- Mining leaderboards and per-character analytics
- Analytics data tables with corporation names, region names via SDE lookup
- Weekly activity heatmap with non-SeAT character name resolution via ESI/zKill
- Comparative analysis: period vs period, miner vs miner, system vs system, ore vs ore

**Notifications & Webhooks**
- Multiple webhook support -- each with independent event toggles
- Discord role pinging with personal vs broadcast notification modes
- Ping content options: show tax amount or general notice with link
- Individual/General scope labels on all notification types in settings UI
- 15 notification types: tax (generated, announcement, reminder, invoice, overdue), moon (arrival, jackpot), events (created, started, completed), theft (detected, critical, active, resolved), reports
- Supported channels: Discord webhooks and Slack (EVE Mail channel is not currently available)
- Unified notification testing panel in diagnostics with all 15 types

**Theft Detection**
- Detect unauthorized mining at corporation moons
- Severity classification (medium, high, critical)
- Active theft monitoring with activity tracking
- Incident management with resolution tracking

**Diagnostics**
- 15-tab diagnostic suite:
  - Test Data -- generate and manage test data
  - Price Provider -- test and compare price sources
  - Cache Health -- price cache status and staleness detection
  - System Validation -- verify configuration and dependencies
  - Settings Health -- audit settings for inconsistencies
  - Tax Trace -- daily summary inspection and live recalculation comparison
  - Data Integrity -- check for orphaned or inconsistent records
  - Valuation Test -- compare ore valuations across providers
  - System Status -- scheduler health and queue monitoring
  - Notification Testing -- unified panel with all 15 notification types and production-parity formatting
  - Moon Extractions -- debug extraction data, notifications, and fractured_at timestamps
  - Tax Pipeline -- trace the full tax calculation pipeline from ledger to invoice
  - Theft Detection -- inspect theft scan results and active monitoring
  - Event Lifecycle -- debug mining event state transitions and participant data
  - Analytics & Reports -- verify report generation and analytics data integrity

**Settings**
- Moon Owner Corporation configuration for moon tax scoping
- Per-corporation tax rates via Switch Corporation Context
- Tax selector (all moon ore / only corp moon ore / no moon ore + regular ore, ice, gas, abyssal, triglavian)
- Configurable price provider (SeAT, Fuzzwork, Janice, Manager Core)
- Payment settings (wallet division, match tolerance, grace period)
- Display settings (currency decimals, pagination, compact mode)

**Documentation**
- Built-in Help & Documentation page with comprehensive guides
- How to Pay Taxes (member guide)
- How to Collect Taxes (director guide)
- Corporation Tax Model explanation with flow table
- Webhooks & Notifications setup guide
- CLI commands reference

**Technical**
- First-time setup wizard (`mining-manager:initialize`) with settings verification, current month data population, and optional historical backfill
- 31 artisan commands with 21 automated scheduled tasks
- Data backup and restore commands (`mining-manager:backup-data`, `mining-manager:restore-data`)
- SeAT 5.x permission integration (4-tier: view, member, director, admin)
- Reprocessing calculator with batch support for compressed ores
- Full settings cache management with per-corporation context

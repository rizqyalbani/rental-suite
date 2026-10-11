<?php

namespace App\Support;

/**
 * The VAT (PPN) briefing checklist (10 questions — started
 * as 8, then gained diskon_dpp and retroaktif_atau_maju once discounts and
 * historical data turned out to be real open questions too). Same content as
 * the standalone "Output VAT Briefing" artifact, kept here as the single
 * source of truth so the Help page's questions and its save-validation
 * (routes/web.php) never drift apart. Add a question by appending a key
 * here; nothing else needs to change — help/index.blade.php's card text is
 * the only place that names the count out loud, so update that too.
 */
class PpnBriefingQuestions
{
    /**
     * @return array<string, array{title: string, why: string, question: string, needsConsultant: bool}>
     */
    public static function all(): array
    {
        return [
            'status_pkp' => [
                'title' => 'VAT-registered status (PKP)',
                'why' => 'VAT (PPN) only has to be collected if the business is registered as a VAT-registered entrepreneur (PKP, Pengusaha Kena Pajak). If it is not, none of the questions below matter yet.',
                'question' => 'Is Demo Store One (and Demo Store Two) registered as PKP, or not yet?',
                'needsConsultant' => false,
            ],
            'kapan_terutang' => [
                'title' => 'When VAT is considered officially due',
                'why' => 'The system currently records revenue when the money is actually received (cash basis) — not when the transaction is created. VAT rules usually follow when the tax invoice (Faktur Pajak) is issued, which can be at a different time.',
                'question' => 'Is VAT calculated when money is received (the deposit and the final payment each carry their own VAT), or when one tax invoice is issued for the whole transaction?',
                'needsConsultant' => true,
            ],
            'faktur_pajak' => [
                'title' => 'Tax invoice (e-Faktur)',
                'why' => 'A VAT-registered business usually has to issue an electronic tax invoice for each transaction through the official tax office (DJP) application — this is different from the regular invoice the system already produces.',
                'question' => 'Will tax invoices be issued manually in the separate DJP application, or is there a plan to connect tax invoice numbers to this system automatically?',
                'needsConsultant' => false,
            ],
            'ppn_masukan' => [
                'title' => 'Input VAT (tax on purchases)',
                'why' => 'Besides the VAT collected from customers (output VAT), there is also VAT paid when buying things for the business (input VAT) — it can usually be deducted from output VAT when filing.',
                'question' => 'Should input VAT on purchases be recorded as well, or focus on output VAT first?',
                'needsConsultant' => false,
            ],
            'tarif_pembulatan' => [
                'title' => 'Rate & rounding',
                'why' => 'The rate currently active in Settings is used to calculate selling prices. For official bookkeeping, tax rounding rules are usually specific.',
                'question' => 'Is the rate currently set in Settings definitely in line with the official rules? Are there special rounding rules that must be followed?',
                'needsConsultant' => true,
            ],
            'diskon_dpp' => [
                'title' => 'Discounts and the tax base (DPP)',
                'why' => 'This system has a per-item discount feature. When there is a discount, the VAT base can be the price BEFORE the discount or AFTER the discount — the results differ, and it usually has to match what is written on the tax invoice / invoice.',
                'question' => 'Is VAT calculated on the price before the discount, or on the price after the discount is applied?',
                'needsConsultant' => true,
            ],
            'dp_hangus' => [
                'title' => 'Cancelled rental & forfeited deposit',
                'why' => 'The "forfeited deposit becomes revenue" feature moves Customer Deposits into Revenue when a rental is cancelled. If VAT was already collected on that deposit, what happens to it needs to be clear.',
                'question' => 'When a deposit that already carried VAT is forfeited (the customer cancels), does the VAT also become revenue, or must it be refunded / corrected?',
                'needsConsultant' => true,
            ],
            'dua_toko' => [
                'title' => 'Two stores, one tax ID (NPWP) or two?',
                'why' => 'This system has 2 stores (Demo Store One and Demo Store Two). With a single NPWP, the VAT report may need to be combined. If they differ, they must stay separate.',
                'question' => 'Do Demo Store One and Demo Store Two share the same NPWP, or are they two separate legal entities?',
                'needsConsultant' => false,
            ],
            'retroaktif_atau_maju' => [
                'title' => 'Applies to new transactions only, or recalculated for old ones too?',
                'why' => 'The system already holds months of rental data from before VAT was switched on. This is different from "when to start using it" (the next question) — it is about the SCOPE of the data: should old transactions have VAT calculated retroactively to catch up, or be treated as not subject to VAT at all?',
                'question' => 'Once VAT posting is really switched on, do OLD transactions (before the activation date) need their VAT recalculated too, or only NEW transactions going forward?',
                'needsConsultant' => true,
            ],
            'kapan_mulai' => [
                'title' => 'When to start using it for real',
                'why' => 'It was discussed earlier that the system might be used for preview only at first, not necessarily real posting.',
                'question' => 'Once the questions above are answered, should we go straight to real posting, or stay on preview for now?',
                'needsConsultant' => false,
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InvestigationController extends Controller
{
    public function index(Request $request): View
    {
        $sql = "
            WITH base AS (
                SELECT
                    r.id,
                    r.customer_name,
                    r.start_date,
                    r.end_date,
                    r.created_at,
                    COALESCE(r.discount, 0) AS discount,
                    COALESCE(r.discount_type, 'fixed') AS discount_type,
                    r.amount_paid,
                    r.payment_kind,
                    SUM(COALESCE(rs.price_incl_tax, s.price, 0)) AS unit_sum,
                    GREATEST(1, DATEDIFF(r.end_date, r.start_date) - 1) AS old_days,
                    GREATEST(1, FLOOR(GREATEST(3, DATEDIFF(r.end_date, r.start_date) + 1) / 3)) AS new_units
                FROM rentals r
                JOIN rental_outfit rs ON rs.rental_id = r.id
                JOIN outfits s ON s.id = rs.outfit_id
                WHERE r.start_date IS NOT NULL
                  AND r.end_date IS NOT NULL
                  AND r.created_at < '2026-06-23 00:00:00'
                GROUP BY r.id, r.customer_name, r.start_date, r.end_date, r.created_at, r.discount, r.discount_type, r.amount_paid, r.payment_kind
            )
            SELECT
                id,
                customer_name,
                DATE_FORMAT(start_date, '%Y-%m-%d') as start_date,
                DATE_FORMAT(end_date, '%Y-%m-%d') as end_date,
                amount_paid,
                payment_kind,
                ROUND(unit_sum, 2) AS unit_sum,
                old_days,
                new_units,
                ROUND(unit_sum * old_days, 2) AS old_gross,
                ROUND(unit_sum * new_units, 2) AS new_gross,
                discount_type,
                discount,
                ROUND(CASE WHEN discount_type='percent' THEN GREATEST(0, unit_sum * old_days - (unit_sum * old_days * discount / 100)) ELSE GREATEST(0, unit_sum * old_days - discount) END, 2) AS old_net,
                ROUND(CASE WHEN discount_type='percent' THEN GREATEST(0, unit_sum * new_units - (unit_sum * new_units * discount / 100)) ELSE GREATEST(0, unit_sum * new_units - discount) END, 2) AS new_net
            FROM base
            WHERE ROUND(unit_sum * old_days, 2) <> ROUND(unit_sum * new_units, 2)
            ORDER BY id DESC
        ";

        $anomalies = DB::select($sql);

        return view('investigations.index', [
            'anomalies' => $anomalies,
        ]);
    }
}

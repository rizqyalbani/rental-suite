<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Emergency View</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f5f5;color:#333;padding:16px}
h1{font-size:20px;margin-bottom:16px;color:#111}
h2{font-size:16px;margin:24px 0 8px;color:#222}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);font-size:13px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #eee;white-space:nowrap}
th{background:#f0f0f0;font-weight:600;color:#555;font-size:11px;text-transform:uppercase}
tr:hover{background:#f9f9f9}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600}
.badge-active{background:#dcfce7;color:#166534}
.badge-returned{background:#f3f4f6;color:#374151}
.badge-cancelled{background:#fee2e2;color:#991b1b}
.badge-available{background:#dcfce7;color:#166534}
.badge-rented{background:#fef3c7;color:#92400e}
.nav{margin-bottom:16px}
.nav a{color:#6366f1;text-decoration:none;font-size:13px;margin-right:12px}
.nav a:hover{text-decoration:underline}
.stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.stat{background:#fff;border-radius:6px;padding:10px 16px;box-shadow:0 1px 3px rgba(0,0,0,.1);font-size:13px}
.stat strong{font-size:18px;display:block;color:#111}
@media(max-width:640px){td,th{padding:6px 4px;font-size:12px}td:nth-child(4),th:nth-child(4){display:none}}
</style>
</head>
<body>

<h1>Emergency View</h1>
<div class="nav">
    <a href="#rentals">Rentals</a>
    <a href="#items">Items</a>
    <a href="{{ route('dashboard') }}">Back to Dashboard</a>
</div>

<div class="stats">
    <div class="stat"><strong>{{ $rentals->count() }}</strong> Rentals</div>
    <div class="stat"><strong>{{ $rentals->where('status', 'active')->count() }}</strong> Active</div>
    <div class="stat"><strong>{{ $items->count() }}</strong> Items</div>
    <div class="stat"><strong>{{ $items->filter(fn($i) => $i->rentals()->where('status', 'active')->count() > 0)->count() }}</strong> Items Rented</div>
</div>

<h2 id="rentals">Rentals</h2>
<table>
<thead>
<tr>
    <th>ID</th>
    <th>Customer</th>
    <th>Phone</th>
    <th>Items</th>
    <th>Start</th>
    <th>End</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
@foreach($rentals as $r)
<tr>
    <td>{{ $r->id }}</td>
    <td>{{ $r->customer_name }}</td>
    <td>{{ $r->phone }}</td>
    <td>{{ $r->outfits->pluck('code')->implode(', ') }}</td>
    <td>{{ $r->start_date->format('d/m/Y') }}</td>
    <td>{{ $r->end_date->format('d/m/Y') }}</td>
    <td><span class="badge badge-{{ $r->status }}">{{ $r->status }}</span></td>
</tr>
@endforeach
</tbody>
</table>

<h2 id="items">Items</h2>
<table>
<thead>
<tr>
    <th>ID</th>
    <th>Code</th>
    <th>New Code</th>
    <th>Description</th>
    <th>Status</th>
</tr>
</thead>
<tbody>
@foreach($items as $item)
@php($rented = $item->rentals()->where('status', 'active')->count() > 0)
<tr>
    <td>{{ $item->id }}</td>
    <td>{{ $item->code }}</td>
    <td>{{ $item->new_code ?? '-' }}</td>
    <td>{{ $item->description ?? '-' }}</td>
    <td><span class="badge {{ $rented ? 'badge-rented' : 'badge-available' }}">{{ $rented ? 'Rented' : 'Available' }}</span></td>
</tr>
@endforeach
</tbody>
</table>

</body>
</html>

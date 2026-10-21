$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000/api/v1'
function Login($email) { $b = @{ email=$email; password='local-seed-password' } | ConvertTo-Json -Compress; (Invoke-RestMethod -Uri "$base/auth/login" -Method Post -ContentType 'application/json' -Body $b).data.access_token }
function Hdr($t) { @{ Authorization = "Bearer $t" } }

$ca = Hdr (Login 'customer@waselna.local')
$rid = (Invoke-RestMethod -Uri "$base/restaurants" -Headers $ca).data[0].id
$item = (Invoke-RestMethod -Uri "$base/restaurants/$rid" -Headers $ca).data.menu[0].items[0].id
Invoke-RestMethod -Uri "$base/cart/items" -Method Post -Headers $ca -ContentType 'application/json' -Body (@{menu_item_id=$item;quantity=1}|ConvertTo-Json -Compress) | Out-Null
$co = $ca + @{ 'Idempotency-Key'=[guid]::NewGuid().ToString(); 'Content-Type'='application/json' }
$oid = (Invoke-RestMethod -Uri "$base/orders" -Method Post -Headers $co -Body (@{delivery_address=@{line1='1 Palm';city='Riyadh'}}|ConvertTo-Json -Compress)).data.id
Invoke-RestMethod -Uri "$base/orders/$oid/pay" -Method Post -Headers $ca | Out-Null

$oa = Hdr (Login 'operator@waselna.local')
Invoke-RestMethod -Uri "$base/restaurant/orders/$oid/accept" -Method Post -Headers $oa | Out-Null
Invoke-RestMethod -Uri "$base/restaurant/orders/$oid/ready" -Method Post -Headers $oa | Out-Null

$da = Hdr (Login 'courier@waselna.local')
Invoke-RestMethod -Uri "$base/rider/profile" -Method Patch -Headers $da -ContentType 'application/json' -Body (@{vehicle_type='motorcycle';phone='+966500000000'}|ConvertTo-Json -Compress) | Out-Null
Invoke-RestMethod -Uri "$base/rider/online" -Method Post -Headers $da | Out-Null
$jid = (Invoke-RestMethod -Uri "$base/dispatch/available" -Headers $da).data[0].id
Invoke-RestMethod -Uri "$base/dispatch/jobs/$jid/accept" -Method Post -Headers $da | Out-Null
Invoke-RestMethod -Uri "$base/dispatch/jobs/$jid/pickup" -Method Post -Headers $da | Out-Null
Invoke-RestMethod -Uri "$base/dispatch/jobs/$jid/transit" -Method Post -Headers $da | Out-Null
Invoke-RestMethod -Uri "$base/dispatch/jobs/$jid/deliver" -Method Post -Headers $da | Out-Null
Write-Host "1. Full chain to delivered OK"

# Notifications for the customer
$notifs = Invoke-RestMethod -Uri "$base/notifications" -Headers $ca
Write-Host "2. NOTIFICATIONS count=$($notifs.data.Count) unread=$($notifs.meta.unread)"

# Review the delivered order
$rev = Invoke-RestMethod -Uri "$base/orders/$oid/review" -Method Post -Headers $ca -ContentType 'application/json' -Body (@{rating=5;comment='Great!'}|ConvertTo-Json -Compress)
Write-Host "3. REVIEW submitted rating=$($rev.data.rating)"

# Restaurant reviews + rating reflected
$rlist = Invoke-RestMethod -Uri "$base/restaurants/$rid/reviews" -Headers $ca
Write-Host "4. RESTAURANT reviews=$($rlist.data.Count)"

# Admin sees everything
$aa = Hdr (Login 'admin@waselna.local')
$sum = Invoke-RestMethod -Uri "$base/admin/summary" -Headers $aa
Write-Host "5. ADMIN delivered=$($sum.data.orders_by_status.delivered) gross=$($sum.data.gross_revenue.amount)"

Write-Host "`n=== FULL APPLICATION FLOW OK ==="

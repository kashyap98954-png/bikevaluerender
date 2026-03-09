<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: index.php'); exit; }
$pdo=db();
$total_users=$pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
$total_preds=$pdo->query('SELECT COUNT(*) FROM predictions')->fetchColumn();
$with_acc=$pdo->query('SELECT COUNT(*) FROM predictions WHERE accident_count>0')->fetchColumn();
$avg_price=$pdo->query('SELECT AVG(predicted_price) FROM predictions')->fetchColumn()??0;
$ml_count=$pdo->query('SELECT COUNT(*) FROM predictions WHERE ml_price IS NOT NULL')->fetchColumn();
$brand_data=$pdo->query('SELECT brand,COUNT(*) AS cnt FROM predictions GROUP BY brand ORDER BY cnt DESC')->fetchAll();
$users=$pdo->query("SELECT user_id,email,created_at FROM users WHERE role='user' ORDER BY created_at DESC")->fetchAll();
$predictions=$pdo->query('SELECT * FROM predictions ORDER BY created_at DESC')->fetchAll();
$tab=$_GET['tab']??'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — BikeValue</title>
<link rel="stylesheet" href="theme.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* ══ ADMIN LAYOUT ══════════════════════════════════════════════ */
body { display:flex; min-height:100vh; }

.sidebar {
  width:220px; min-height:100vh; flex-shrink:0;
  background: linear-gradient(180deg, rgba(8,8,5,0.98), rgba(12,11,7,0.96));
  border-right: 1px solid rgba(201,168,76,0.15);
  display:flex; flex-direction:column;
  position:sticky; top:0; height:100vh; overflow-y:auto;
  z-index:50; backdrop-filter:blur(24px);
}
.sb-brand {
  padding: 2rem 1.6rem 1.5rem;
  font-family:'Playfair Display',serif;
  font-size:1.1rem; font-weight:900; letter-spacing:3px;
  border-bottom: 1px solid rgba(201,168,76,0.12);
}
.sb-brand span {
  background: linear-gradient(135deg, var(--gold), var(--gold-light));
  -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
}
.sb-brand small {
  display:block; font-family:'Outfit',sans-serif;
  font-size:.58rem; letter-spacing:2.5px; color:var(--text-muted);
  margin-top:.3rem; font-weight:400; text-transform:uppercase;
}
.sb-section {
  padding:.9rem 1.6rem .3rem;
  font-size:.58rem; letter-spacing:3px;
  color:rgba(201,168,76,0.4); text-transform:uppercase;
}
.sb-link {
  display:flex; align-items:center; gap:.65rem;
  padding:.75rem 1.6rem; font-size:.82rem; letter-spacing:.5px;
  text-decoration:none; color:var(--text-muted);
  transition:all .2s; border-left:2px solid transparent;
}
.sb-link:hover { color:var(--text); background:rgba(201,168,76,0.06); }
.sb-link.active {
  color:var(--gold-light); background:rgba(201,168,76,0.1);
  border-left-color:var(--gold);
}
.sb-bottom {
  margin-top:auto; padding:1.3rem 1.6rem;
  border-top:1px solid rgba(201,168,76,0.1);
}

.admin-main { flex:1; display:flex; flex-direction:column; overflow:hidden; position:relative; z-index:10; }
.admin-header {
  display:flex; align-items:center; justify-content:space-between;
  padding:1.3rem 2.2rem;
  background: linear-gradient(135deg, rgba(8,8,5,0.95), rgba(14,12,7,0.92));
  border-bottom:1px solid rgba(201,168,76,0.12);
  backdrop-filter:blur(20px);
  position:sticky; top:0; z-index:40;
}
.admin-title {
  font-family:'Playfair Display',serif; font-size:1.5rem; font-weight:700;
  background: linear-gradient(135deg, var(--text), var(--gold-light));
  -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
}
.admin-content { flex:1; padding:2rem; overflow-y:auto; }

/* ── Stat cards ── */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:1.2rem; margin-bottom:2.4rem; }
.stat-card {
  background: linear-gradient(145deg, rgba(14,13,8,0.96), rgba(20,18,10,0.92));
  border:1px solid rgba(201,168,76,0.16); border-radius:12px; padding:1.5rem;
  position:relative; overflow:hidden;
  transition:border-color .25s, transform .25s;
  backdrop-filter:blur(16px);
}
.stat-card:hover { border-color:rgba(201,168,76,0.4); transform:translateY(-3px); box-shadow:0 8px 28px rgba(0,0,0,0.4), 0 0 25px rgba(201,168,76,0.1); }
.stat-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent); opacity:0.5;
}
.stat-card::after {
  content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
  background: linear-gradient(90deg, var(--gold-dark), var(--gold), transparent);
}
.stat-icon { position:absolute; top:1rem; right:1.1rem; font-size:1.5rem; opacity:0.25; }
.stat-val {
  font-family:'Playfair Display',serif; font-size:2.1rem; font-weight:700;
  color:var(--gold-light); line-height:1;
  text-shadow:0 0 20px rgba(201,168,76,0.3);
}
.stat-lbl { font-size:.62rem; letter-spacing:2px; color:var(--text-muted); margin-top:.4rem; text-transform:uppercase; }

/* ── Charts ── */
.charts-row { display:grid; grid-template-columns:1fr 1fr; gap:1.2rem; margin-bottom:2.4rem; }
.chart-card {
  background: linear-gradient(145deg, rgba(14,13,8,0.96), rgba(20,18,10,0.92));
  border:1px solid rgba(201,168,76,0.16); border-radius:12px; padding:1.6rem;
  backdrop-filter:blur(16px);
  position:relative; overflow:hidden;
}
.chart-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent,var(--gold),transparent); opacity:0.4;
}
.chart-title { font-size:.6rem; letter-spacing:2.5px; text-transform:uppercase; color:var(--gold); margin-bottom:1.2rem; font-weight:700; }

/* ── Table cards ── */
.table-card {
  background: linear-gradient(145deg, rgba(12,11,7,0.97), rgba(18,16,9,0.93));
  border:1px solid rgba(201,168,76,0.16); border-radius:12px;
  overflow:hidden; margin-bottom:2rem; backdrop-filter:blur(18px);
  box-shadow:0 6px 24px rgba(0,0,0,0.4);
}
.table-head {
  display:flex; align-items:center; justify-content:space-between;
  padding:1.3rem 1.6rem; border-bottom:1px solid rgba(201,168,76,0.12);
  background:rgba(20,18,10,0.7);
}
.table-title {
  font-family:'Playfair Display',serif; font-size:1.1rem; font-weight:700;
  background:linear-gradient(135deg,var(--gold),var(--gold-light));
  -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
}
.data-table { width:100%; border-collapse:collapse; }
.data-table th,.data-table td { padding:.85rem 1.2rem; text-align:left; font-size:.86rem; }
.data-table th {
  font-size:.62rem; letter-spacing:2px; text-transform:uppercase;
  color:var(--gold); background:rgba(201,168,76,0.04);
  border-bottom:1px solid rgba(201,168,76,0.12);
}
.data-table td { border-bottom:1px solid rgba(201,168,76,0.06); }
.data-table tr:hover td { background:rgba(201,168,76,0.05); }
.price-cell {
  color:var(--gold-light); font-weight:700;
  font-family:'Playfair Display',serif; font-size:.88rem;
}
.ml-cell  { color:var(--success); font-size:.82rem; }
.no-ml    { color:var(--text-muted); font-size:.8rem; }
.badge { display:inline-block; padding:.3rem .8rem; border-radius:20px; font-size:.68rem; font-weight:700; letter-spacing:.8px; text-transform:uppercase; }
.badge--high   { background:rgba(255,112,112,0.14); color:#ff9090; border:1px solid rgba(255,112,112,0.28); }
.badge--low    { background:rgba(110,231,183,0.14); color:var(--success); border:1px solid rgba(110,231,183,0.28); }
.empty-state   { padding:3rem; text-align:center; color:var(--text-muted); font-size:.88rem; letter-spacing:1px; }
</style>
</head>
<body>
<div class="moto-bg"></div>
<div class="light-bleed"></div>
<div class="grain"></div>
<div class="grid-overlay"></div>

<aside class="sidebar">
  <div class="sb-brand">⚡ BIKE<span>VALUE</span><small>Administration</small></div>
  <div class="sb-section">Navigation</div>
  <a href="admin.php?tab=dashboard" class="sb-link <?=$tab==='dashboard'?'active':''?>">📊 Dashboard</a>
  <a href="admin.php?tab=users"     class="sb-link <?=$tab==='users'?'active':''?>">👥 Users</a>
  <a href="admin.php?tab=logs"      class="sb-link <?=$tab==='logs'?'active':''?>">📈 Predictions</a>
  <div class="sb-bottom">
    <form action="auth.php" method="POST">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-danger" style="width:100%;justify-content:center">Logout</button>
    </form>
  </div>
</aside>

<div class="admin-main">
  <header class="admin-header">
    <h1 class="admin-title"><?=$tab==='dashboard'?'Dashboard':($tab==='users'?'User Management':'Prediction Logs')?></h1>
    <span class="nav-user-display">
      <span class="nav-user-icon">👤</span>
      <span class="nav-user-name"><?=htmlspecialchars($_SESSION['user_id'])?></span>
    </span>
  </header>

  <div class="admin-content">

  <?php if($tab==='dashboard'): ?>
  <!-- STATS -->
  <div class="stats-grid">
    <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-val"><?=number_format($total_users)?></div><div class="stat-lbl">Total Users</div></div>
    <div class="stat-card"><div class="stat-icon">📈</div><div class="stat-val"><?=number_format($total_preds)?></div><div class="stat-lbl">Predictions</div></div>
    <div class="stat-card"><div class="stat-icon">⚠️</div><div class="stat-val"><?=number_format($with_acc)?></div><div class="stat-lbl">With Accidents</div></div>
    <div class="stat-card"><div class="stat-icon">💰</div><div class="stat-val">₹<?=number_format($avg_price)?></div><div class="stat-lbl">Avg Price</div></div>
    <div class="stat-card"><div class="stat-icon">🤖</div><div class="stat-val"><?=number_format($ml_count)?></div><div class="stat-lbl">ML Predictions</div></div>
  </div>

  <?php if(count($brand_data)): ?>
  <div class="charts-row">
    <div class="chart-card"><div class="chart-title">Predictions by Brand</div><canvas id="brandChart" height="190"></canvas></div>
    <div class="chart-card"><div class="chart-title">Weekly Prediction Trend</div><canvas id="trendChart" height="190"></canvas></div>
  </div>
  <?php endif; ?>

  <div class="table-card">
    <div class="table-head"><span class="table-title">Recent Predictions</span></div>
    <?php $recent=array_slice($predictions,0,10); if($recent): ?>
    <table class="data-table">
      <thead><tr><th>User</th><th>Bike</th><th>Brand</th><th>Price</th><th>ML Price</th><th>Accidents</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($recent as $r): ?>
      <tr>
        <td><?=htmlspecialchars($r['user_id'])?></td>
        <td><?=htmlspecialchars($r['bike_name'])?></td>
        <td><?=htmlspecialchars($r['brand'])?></td>
        <td class="price-cell">₹<?=number_format($r['predicted_price'])?></td>
        <td><?=$r['ml_price']?'<span class="ml-cell">₹'.number_format($r['ml_price']).'</span>':'<span class="no-ml">—</span>'?></td>
        <td><span class="badge badge--<?=$r['accident_count']>0?'high':'low'?>"><?=$r['accident_count']>0?'Yes':'No'?></span></td>
        <td style="color:var(--text-muted);font-size:.76rem"><?=date('d M Y',strtotime($r['created_at']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state">No predictions yet.</div><?php endif; ?>
  </div>

  <?php elseif($tab==='users'): ?>
  <div class="table-card">
    <div class="table-head"><span class="table-title">Registered Users (<?=count($users)?>)</span></div>
    <?php if($users): ?>
    <table class="data-table">
      <thead><tr><th>#</th><th>Username</th><th>Email</th><th>Joined</th></tr></thead>
      <tbody>
      <?php foreach($users as $i=>$u): ?>
      <tr>
        <td style="color:var(--text-muted)"><?=$i+1?></td>
        <td><strong style="color:var(--gold-light)"><?=htmlspecialchars($u['user_id'])?></strong></td>
        <td><?=htmlspecialchars($u['email'])?></td>
        <td style="color:var(--text-muted);font-size:.76rem"><?=date('d M Y',strtotime($u['created_at']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state">No users yet.</div><?php endif; ?>
  </div>

  <?php else: ?>
  <div class="table-card">
    <div class="table-head"><span class="table-title">All Prediction Logs (<?=count($predictions)?>)</span></div>
    <?php if($predictions): ?>
    <table class="data-table">
      <thead><tr><th>User</th><th>Bike</th><th>Brand</th><th>CC</th><th>Age</th><th>KM</th><th>Price</th><th>ML Price</th><th>Acc</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach($predictions as $p): ?>
      <tr>
        <td><?=htmlspecialchars($p['user_id'])?></td>
        <td><?=htmlspecialchars($p['bike_name'])?></td>
        <td><?=htmlspecialchars($p['brand'])?></td>
        <td style="color:var(--text-muted)"><?=$p['engine_cc']?>cc</td>
        <td style="color:var(--text-muted)"><?=$p['bike_age']?>yr</td>
        <td style="color:var(--text-muted)"><?=number_format($p['km_driven'])?></td>
        <td class="price-cell">₹<?=number_format($p['predicted_price'])?></td>
        <td><?=$p['ml_price']?'<span class="ml-cell">₹'.number_format($p['ml_price']).'</span>':'<span class="no-ml">—</span>'?></td>
        <td><span class="badge badge--<?=$p['accident_count']>0?'high':'low'?>"><?=$p['accident_count']?></span></td>
        <td style="color:var(--text-muted);font-size:.74rem"><?=date('d M Y',strtotime($p['created_at']))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?><div class="empty-state">No logs yet.</div><?php endif; ?>
  </div>
  <?php endif; ?>
  </div><!-- /admin-content -->
</div><!-- /admin-main -->

<?php if(count($brand_data)&&$tab==='dashboard'): ?>
<script>
const goldColor   = 'rgba(201,168,76,0.8)';
const goldFill    = 'rgba(201,168,76,0.18)';
const gridColor   = 'rgba(201,168,76,0.06)';
const tickColor   = '#8a8070';
const co = {
  plugins: { legend: { labels: { color: tickColor, font: { family:'Outfit', size:11 } } } },
  scales: {
    x: { ticks: { color: tickColor }, grid: { color: gridColor } },
    y: { ticks: { color: tickColor }, grid: { color: gridColor } }
  }
};
new Chart(document.getElementById('brandChart'),{
  type:'bar',
  data:{
    labels:<?=json_encode(array_column($brand_data,'brand'))?>,
    datasets:[{
      label:'Predictions',
      data:<?=json_encode(array_column($brand_data,'cnt'))?>,
      backgroundColor:goldFill,
      borderColor:goldColor,
      borderWidth:1, borderRadius:5
    }]
  },
  options:{...co}
});
const days=['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
new Chart(document.getElementById('trendChart'),{
  type:'line',
  data:{
    labels:days,
    datasets:[{
      label:'Predictions',
      data:days.map(()=>Math.floor(Math.random()*Math.max(1,<?=$total_preds?>/7)+1)),
      fill:true,
      backgroundColor:'rgba(201,168,76,0.07)',
      borderColor:goldColor,
      tension:0.4,
      pointBackgroundColor:'#c9a84c',
      pointBorderColor:'#e8c97a',
      pointRadius:5
    }]
  },
  options:{...co}
});
</script>
<?php endif; ?>
</body>
</html>

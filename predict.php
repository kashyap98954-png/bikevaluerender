<?php
session_start();
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header('Location: index.php'); exit;
}

$brands = ['Royal Enfield','Yamaha','Honda','Bajaj','TVS','KTM','Suzuki','Kawasaki','Hero','Triumph'];
$bike_names_by_brand = [
    'Royal Enfield' => ['Classic 350','Classic 500','Bullet 350','Bullet 500','Thunderbird 350','Thunderbird 500','Himalayan','Meteor 350','Hunter 350','Super Meteor 650','Continental GT 650','Interceptor 650'],
    'Yamaha'        => ['FZ-S V3','FZ25','R15 V4','MT-15','R3','FZS-FI','Fazer 25','YZF R15','Ray ZR','Fascino','Alpha','SZ-RR'],
    'Honda'         => ['CB Shine','CB Hornet 160R','CB350','CB500F','CBR650R','Activa 6G','Unicorn','Livo','SP 125','Shine SP','CB200X','NX200'],
    'Bajaj'         => ['Pulsar 150','Pulsar 180','Pulsar 220F','Pulsar NS200','Pulsar RS200','Dominar 400','Avenger 220','CT100','Platina','Pulsar N250','Pulsar F250','Dominar 250'],
    'TVS'           => ['Apache RTR 160','Apache RTR 200','Apache RR 310','Jupiter','NTorq 125','Raider 125','Ronin','iQube Electric','Star City+','Sport','HLX 125','Radeon'],
    'KTM'           => ['Duke 200','Duke 250','Duke 390','RC 200','RC 390','Adventure 250','Adventure 390','Duke 125','RC 125'],
    'Suzuki'        => ['Gixxer SF','Gixxer 250','V-Strom 650','Access 125','Burgman Street','Intruder 150','Avenis 125'],
    'Kawasaki'      => ['Ninja 300','Ninja 400','Ninja 650','Z650','Versys 650','W175','Vulcan S'],
    'Hero'          => ['Splendor Plus','Passion Pro','HF Deluxe','Glamour','Xtreme 160R','Xpulse 200','Maestro Edge','Destini 125','Super Splendor'],
    'Triumph'       => ['Tiger 660','Trident 660'],
];
$cities    = ['Mumbai','Delhi','Bangalore','Chennai','Hyderabad','Pune','Kolkata','Ahmedabad','Jaipur','Lucknow','Chandigarh','Kochi'];
$acc_types = ['none','minor','major','severe'];

$prev   = $_SESSION['last_form'] ?? [];
$isEdit = isset($_GET['edit']) && !empty($prev);
$pv     = $isEdit ? $prev : [];

$prediction = $ml_price = $ml_adjusted = $ml_impact = $ml_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand            = $_POST['brand']             ?? '';
    $bike_name        = $_POST['bike_name']          ?? '';
    $engine_capacity  = (float)($_POST['engine_capacity'] ?? 0);
    $age              = (float)($_POST['age']         ?? 0);
    $owner            = (float)($_POST['owner']       ?? 1);
    $kms_driven       = (float)($_POST['kms_driven']  ?? 0);
    $city             = $_POST['city']               ?? '';
    $accident_count   = (float)($_POST['accident_count'] ?? 0);
    $accident_history = $accident_count == 0 ? 'none' : trim($_POST['accident_history'] ?? 'none');

    $_SESSION['last_form'] = compact('brand','bike_name','engine_capacity','age','owner','kms_driven','city','accident_count','accident_history');

    if ($engine_capacity <= 0) $ml_error = 'Please enter a valid engine capacity (cc).';

    if (function_exists('curl_init')) {
        $payload = [
            'bike_name' => strtolower($bike_name), 'kms_driven' => $kms_driven,
            'owner' => $owner, 'age' => $age, 'city' => strtolower($city),
            'engine_capacity' => $engine_capacity, 'accident_count' => $accident_count,
            'brand' => strtolower($brand), 'accident_history' => $accident_history,
        ];
        $raw = json_encode($payload);
        $ch  = curl_init((getenv('ML_API_URL') ?: 'http://localhost:5000') . '/predict');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $raw]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $d = json_decode($res, true);
            $ml_price    = isset($d['predicted_price'])    ? (float)$d['predicted_price']    : null;
            $ml_adjusted = isset($d['predicted_adjusted']) ? (float)$d['predicted_adjusted'] : null;
            $ml_impact   = ($ml_price !== null && $ml_adjusted !== null)
                ? round($ml_price - $ml_adjusted, 2)
                : (isset($d['accident_impact']) ? (float)$d['accident_impact'] : null);
            $prediction['debug_payload']  = $raw;
            $prediction['debug_response'] = $res;
        } else { $ml_error = 'ML API offline — run: python ml_api.py'; }
    }

    $final_price = $ml_price !== null ? $ml_price : 0;

    // ── CITY PRICE ADJUSTMENT (+/- 3,000–5,000) ──────────────────
    $city_offsets = [
        'mumbai'     =>  5000, 'delhi'      =>  4500,
        'bangalore'  =>  3800, 'chennai'    =>  2500,
        'hyderabad'  =>  3200, 'pune'       =>  4000,
        'kolkata'    => -3000, 'ahmedabad'  => -2500,
        'jaipur'     => -3500, 'lucknow'    => -4000,
        'chandigarh' =>  1500, 'kochi'      =>  2000,
    ];
    $city_key   = strtolower(trim($city));
    $city_delta = $city_offsets[$city_key] ?? 0;
    if ($final_price > 0) {
        $final_price  = round($final_price  + $city_delta);
        if ($ml_price    !== null) $ml_price    = round($ml_price    + $city_delta);
        if ($ml_adjusted !== null) $ml_adjusted = round($ml_adjusted + $city_delta);
        if ($ml_impact   !== null) $ml_impact   = round(abs($ml_price - $ml_adjusted));
    }

    if (empty($ml_error) && function_exists('curl_init')) {
        $chh = curl_init((getenv('ML_API_URL') ?: 'http://localhost:5000') . '/health');
        curl_setopt_array($chh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        if (!curl_exec($chh)) $ml_error = 'Could not reach ML API (health check failed)';
        curl_close($chh);
    }

    $db_ml = ($ml_adjusted !== null) ? $ml_adjusted : $ml_price;
    db()->prepare('INSERT INTO predictions(user_id,bike_name,brand,engine_cc,bike_age,owner_type,km_driven,accident_history,accident_count,predicted_price,ml_price)VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$_SESSION['user_id'],$bike_name,$brand,(int)$engine_capacity,(int)$age,"Owner $owner",(int)$kms_driven,(int)($accident_count>0),(int)$accident_count,$final_price,$db_ml]);

    $prediction = [
        'ml_price' => $ml_price, 'ml_adjusted' => $ml_adjusted,
        'ml_impact' => $ml_impact, 'final_price' => $final_price,
        'ml_error' => $ml_error, 'has_accident' => $accident_count > 0,
        'brand' => $brand, 'bike_name' => $bike_name,
        'params' => [
            ['label'=>'Brand',            'val'=>$brand,                                'impact'=>'High'],
            ['label'=>'Model',            'val'=>$bike_name,                            'impact'=>'Medium'],
            ['label'=>'Engine',           'val'=>$engine_capacity.' cc',                'impact'=>'High'],
            ['label'=>'Age',              'val'=>$age.' yr'.($age!=1?'s':''),           'impact'=>$age<=2?'Low':'High'],
            ['label'=>'Ownership',        'val'=>'Owner '.(int)$owner,                  'impact'=>$owner==1?'Low':'High'],
            ['label'=>'Odometer',         'val'=>number_format($kms_driven).' km',      'impact'=>$kms_driven<30000?'Low':'High'],
            ['label'=>'City',             'val'=>ucfirst($city),                        'impact'=>'Medium'],
            ['label'=>'Accident Severity','val'=>ucfirst($accident_history),            'impact'=>$accident_count>0?'High':'Low'],
            ['label'=>'Accident Count',   'val'=>(int)$accident_count,                  'impact'=>$accident_count>0?'High':'Low'],
        ],
    ];
}

$pBrand    = htmlspecialchars($pv['brand']           ?? '');
$pCC       = htmlspecialchars($pv['engine_capacity'] ?? '');
$pAge      = htmlspecialchars($pv['age']             ?? '');
$pOwner    = htmlspecialchars($pv['owner']           ?? 1);
$pKms      = htmlspecialchars($pv['kms_driven']      ?? '');
$pCity     = htmlspecialchars($pv['city']            ?? '');
$pAccCnt   = htmlspecialchars($pv['accident_count']  ?? 0);
$pAccHist  = htmlspecialchars($pv['accident_history']?? 'none');
$pBikeName = strtolower($pv['bike_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $prediction ? 'Valuation Result' : 'Predict Value' ?> — BikeValue</title>
<link rel="stylesheet" href="theme.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,600;1,300;1,600&display=swap" rel="stylesheet">
<style>
/* ══ PAGE OVERRIDES ══════════════════════════════════════════ */

/* Background: triumph logo watermark */
.moto-bg::before {
  background: url('triumphh.jpg') center 55% / cover no-repeat !important;
  filter: saturate(0) brightness(0.22) !important;
  animation: bgDrift 28s ease-in-out infinite alternate !important;
}
@keyframes bgDrift {
  from { transform: scale(1.0) translateX(0); }
  to   { transform: scale(1.06) translateX(-18px); }
}
.moto-bg::after {
  background:
    linear-gradient(135deg,
      rgba(8,8,6,0.97)  0%,
      rgba(14,12,6,0.93) 30%,
      rgba(20,16,8,0.85) 58%,
      rgba(10,9,5,0.96)  80%,
      rgba(8,8,6,0.97)  100%),
    radial-gradient(ellipse 70% 70% at 70% 45%,
      rgba(201,168,76,0.07) 0%, transparent 65%) !important;
}

main { max-width: 960px; padding: 1.2rem 1.5rem 5rem; }

/* ── Edit banner ── */
.edit-banner {
  display: flex; align-items: flex-start; gap: 1rem;
  background: rgba(201,168,76,0.07);
  border: 1px solid rgba(201,168,76,0.22);
  border-left: 3px solid var(--gold);
  border-radius: 8px; padding: 1rem 1.4rem;
  margin-bottom: 1.8rem; font-size: 0.84rem;
  color: rgba(201,168,76,0.88); line-height: 1.65;
}
.edit-banner strong { color: var(--gold); }

/* ── Result header ── */
.result-header {
  display: flex; align-items: center; gap: 1.8rem;
  margin-bottom: 2.4rem; padding-bottom: 2rem;
  border-bottom: 1px solid rgba(201,168,76,0.14);
}
.bike-id-badge {
  flex-shrink: 0; width: 72px; height: 72px; border-radius: 50%;
  background: linear-gradient(145deg, rgba(201,168,76,0.2), rgba(201,168,76,0.06));
  border: 1px solid rgba(201,168,76,0.4);
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem;
  box-shadow: 0 0 30px rgba(201,168,76,0.18), inset 0 1px 0 rgba(255,255,255,0.1);
}
.result-meta h2 {
  font-family: 'Playfair Display', serif;
  font-size: 1.9rem; font-weight: 700; line-height: 1.18; color: var(--text);
}
.result-sub {
  font-size: 0.65rem; letter-spacing: 3px; text-transform: uppercase;
  color: var(--text-muted); margin-top: 0.4rem;
}

/* ── PRICE CARDS ── */
.price-cards {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
  gap: 1.4rem; margin-bottom: 2.8rem;
}
.price-box {
  border-radius: 14px; padding: 2.2rem 1.8rem; text-align: center;
  position: relative; overflow: hidden;
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1), box-shadow 0.3s;
}
.price-box:hover { transform: translateY(-6px); }
.price-box::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
}

/* MAIN price box — gold glow */
.price-box--main {
  background: linear-gradient(145deg, rgba(20,17,8,0.96), rgba(28,22,8,0.92));
  border: 1px solid rgba(201,168,76,0.4);
  box-shadow: 0 0 50px rgba(201,168,76,0.1),
              inset 0 1px 0 rgba(201,168,76,0.15),
              0 12px 35px rgba(0,0,0,0.5);
}
.price-box--main::before {
  background: linear-gradient(90deg, transparent, var(--gold), var(--gold-light), transparent);
}
.price-box--main::after {
  content: ''; position: absolute; top: -50px; right: -50px;
  width: 140px; height: 140px;
  background: radial-gradient(circle, rgba(201,168,76,0.12), transparent 65%);
  pointer-events: none;
}

/* Adjusted price box — muted red */
.price-box--adj {
  background: linear-gradient(145deg, rgba(20,12,12,0.94), rgba(28,14,14,0.9));
  border: 1px solid rgba(255,112,112,0.3);
  box-shadow: 0 0 30px rgba(255,112,112,0.06), 0 10px 28px rgba(0,0,0,0.45);
}
.price-box--adj::before {
  background: linear-gradient(90deg, transparent, rgba(255,130,130,0.7), transparent);
}

/* Price text elements */
.ml-badge {
  display: inline-flex; align-items: center; gap: 0.4rem;
  background: rgba(110,231,183,0.1);
  border: 1px solid rgba(110,231,183,0.28);
  color: #6ee7b7;
  font-size: 0.56rem; letter-spacing: 2.5px;
  padding: 0.22rem 0.9rem; border-radius: 20px;
  margin-bottom: 0.8rem;
}
.ml-badge::before {
  content: '●'; font-size: 0.48rem;
  animation: pulseDot 1.8s ease-in-out infinite;
}
@keyframes pulseDot {
  0%,100% { opacity: 1; } 50% { opacity: 0.3; }
}
@keyframes pulseGlow {
  0%,100% { opacity: 1; } 50% { opacity: 0.3; }
}

.price-label {
  font-size: 0.6rem; letter-spacing: 3.5px; text-transform: uppercase;
  color: var(--text-muted); margin-bottom: 1rem;
}

/* ★ THE FIX: price uses solid gold — no transparent gradient ★ */
.price-value {
  font-family: 'Playfair Display', serif;
  font-size: 2.6rem; font-weight: 900; line-height: 1;
  color: var(--gold-light);
  text-shadow: 0 0 30px rgba(201,168,76,0.4), 0 0 60px rgba(201,168,76,0.2);
  letter-spacing: -0.5px;
}
.price-box--adj .price-value {
  color: #ffa0a0;
  text-shadow: 0 0 25px rgba(255,112,112,0.3);
}
.price-note   { font-size: 0.72rem; color: var(--text-muted); margin-top: 0.65rem; }
.price-impact { font-size: 0.84rem; color: #ff9090; margin-top: 0.5rem; font-weight: 700; }

/* ── Breakdown table ── */
.breakdown-title {
  font-size: 0.6rem; letter-spacing: 3.5px; text-transform: uppercase;
  color: var(--gold); margin-bottom: 1.1rem; font-weight: 700;
  display: flex; align-items: center; gap: 0.8rem;
}
.breakdown-title::after { content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, rgba(201,168,76,0.25), transparent); }

table { width: 100%; border-collapse: collapse; }
th, td { padding: 0.88rem 1.1rem; text-align: left; font-size: 0.88rem; }
th {
  font-size: 0.6rem; letter-spacing: 2.5px; text-transform: uppercase;
  color: var(--gold); border-bottom: 1px solid rgba(201,168,76,0.16);
  background: rgba(201,168,76,0.03);
}
tr:not(:last-child) td { border-bottom: 1px solid rgba(201,168,76,0.07); }
tr:hover td { background: rgba(201,168,76,0.04); }
td:first-child { color: var(--text-muted); font-size: 0.82rem; letter-spacing: 0.5px; }
td:nth-child(2) { font-weight: 600; color: var(--text); }

/* ── Refine button ── */
.btn-refine {
  display: inline-flex; align-items: center; gap: 0.8rem;
  font-family: 'Cormorant Garamond', serif;
  font-style: italic; font-size: 1.05rem; font-weight: 600;
  letter-spacing: 1px; color: var(--gold);
  background: transparent;
  border: 1px solid rgba(201,168,76,0.3); border-radius: 6px;
  padding: 0.72rem 1.8rem 0.72rem 1.2rem;
  text-decoration: none; cursor: pointer;
  position: relative; overflow: hidden;
  transition: all 0.3s ease;
}
.btn-refine::before {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(201,168,76,0.07), transparent 60%);
  opacity: 0; transition: opacity 0.3s;
}
.btn-refine:hover { color: var(--gold-light); border-color: rgba(201,168,76,0.7); box-shadow: 0 0 24px rgba(201,168,76,0.16); }
.btn-refine:hover::before { opacity: 1; }
.arrow-ring {
  width: 26px; height: 26px;
  border: 1px solid rgba(201,168,76,0.45); border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.88rem; font-style: normal; flex-shrink: 0;
  transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1);
}
.btn-refine:hover .arrow-ring { transform: translateX(-4px); border-color: rgba(201,168,76,0.85); }

/* ── Actions ── */
.actions { display: flex; gap: 1rem; margin-top: 2.6rem; align-items: center; flex-wrap: wrap; }
.actions-divider { width: 1px; height: 34px; background: rgba(201,168,76,0.18); }

/* ── Inline code chip ── */
code {
  background: rgba(201,168,76,0.12); padding: 0.1rem 0.45rem;
  border-radius: 4px; font-size: 0.82rem; color: var(--gold-light);
  border: 1px solid rgba(201,168,76,0.2);
}

/* ── FORM GRID — clean 2-column layout ── */
.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.4rem 2rem;
  margin-bottom: 0.5rem;
}
.form-grid-2 .full-width { grid-column: 1 / -1; }

.form-section-label {
  display: flex; align-items: center; gap: 1rem;
  margin-bottom: 1.2rem;
}
.form-section-label span {
  font-size: 0.6rem; letter-spacing: 3.5px; text-transform: uppercase;
  color: var(--gold); font-weight: 700; white-space: nowrap;
}
.form-section-label::before { content: ''; width: 20px; height: 1px; background: var(--gold); opacity: 0.5; }
.form-section-label::after  { content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, rgba(201,168,76,0.3), transparent); }

@media (max-width: 680px) {
  .result-header { flex-direction: column; gap: 1rem; }
  .actions { flex-direction: column; align-items: stretch; }
  .actions-divider { display: none; }
  .btn-refine { justify-content: center; }
  .price-value { font-size: 2rem; }
  .form-grid-2 { grid-template-columns: 1fr; }
  .form-grid-2 .full-width { grid-column: 1; }
  .form-grid-2 .full-width > div { grid-template-columns: 1fr !important; }
}
</style>
</head>
<body>
<div class="moto-bg"></div>
<div class="light-bleed"></div>
<div class="grain"></div>
<div class="grid-overlay"></div>

<nav class="navbar">
  <a class="nav-brand" href="index.php">⚡ BIKE<span>VALUE</span></a>
  <div style="display:flex;align-items:center;gap:1rem">
    <span class="nav-user-display">
      <span class="nav-user-icon">👤</span>
      <span class="nav-user-name"><?= htmlspecialchars($_SESSION['user_id']) ?></span>
    </span>
    <form action="auth.php" method="POST" style="margin:0">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-danger">Logout</button>
    </form>
  </div>
</nav>

<main>
<?php if (!$prediction): ?>

<!-- ══════════ FORM ══════════ -->
<div class="glass-card anim-1">

  <div class="page-eyebrow"><span>Valuation Engine</span> // ML Precision Model</div>

  <h2 class="card-title" style="font-size:2.2rem;line-height:1.15">
    <?= $isEdit
      ? 'Refine Your <em style="font-style:italic;color:var(--gold-light)">Inputs</em>'
      : 'Predict Your Bike\'s <em style="font-style:italic;color:var(--gold-light)">Value</em>'
    ?>
  </h2>
  <p class="card-sub">
    <?= $isEdit ? 'Previous inputs restored — adjust and re-run' : 'Fill every field for maximum ML accuracy' ?>
  </p>

  <?php if ($isEdit): ?>
  <div class="edit-banner">
    <span>✎</span>
    <div>
      <strong>Editing your previous entry.</strong> Every field has been restored.
      Adjust anything and hit <strong>Predict</strong> to get a fresh valuation.
    </div>
  </div>
  <?php endif; ?>

  <div class="info-banner">
    ✦ Keep <code>python ml_api.py</code> running in VS Code for live ML predictions.
  </div>

  <form action="predict.php" method="POST">
    <?php echo '<script>const bikeNamesByBrand='.json_encode($bike_names_by_brand).';</script>'; ?>

    <!-- ══ BIKE DETAILS SECTION ══ -->
    <div class="form-section-label"><span>Bike Details</span></div>

    <div class="form-grid-2">

      <!-- Row 1: Brand | Engine CC -->
      <div class="form-group">
        <label class="form-label">Brand</label>
        <select name="brand" id="brandSelect" class="form-input" required onchange="updateBikeNames()">
          <option value="" disabled <?= !$pBrand ? 'selected' : '' ?>>Select Brand</option>
          <?php foreach ($brands as $b): ?>
            <option <?= $pBrand === htmlspecialchars($b) ? 'selected' : '' ?>><?= $b ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Engine Capacity (cc)</label>
        <input type="number" name="engine_capacity" id="engineCC" class="form-input"
               placeholder="Auto-filled or enter manually" min="0" max="3000"
               value="<?= $pCC ?>" required>
        <span id="cc-hint" style="font-size:.7rem;letter-spacing:.5px;margin-top:.35rem;color:var(--text-muted)"></span>
      </div>

      <!-- Row 2: Model spans full width -->
      <div class="form-group full-width">
        <label class="form-label">Model</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.9rem">
          <select name="bike_name_select" id="bikeNameSelect" class="form-input"
                  onchange="updateBikeNameFromSelect(); updateEngineCC()">
            <option value="">Select from List</option>
          </select>
          <input type="text" name="bike_name" id="bikeNameInput" class="form-input"
                 placeholder="Or type any bike model name"
                 value="<?= $pBikeName ?>" required>
        </div>
        <span id="model-hint" style="font-size:.7rem;letter-spacing:.5px;margin-top:.35rem;color:var(--text-muted)"></span>
      </div>

      <!-- Row 3: Age | Owner -->
      <div class="form-group">
        <label class="form-label">Bike Age (Years)</label>
        <input type="number" name="age" class="form-input" placeholder="e.g. 3"
               min="0" max="30" value="<?= $pAge ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Owner Number</label>
        <input type="number" name="owner" class="form-input" placeholder="1 = first owner"
               min="1" max="5" value="<?= $pOwner ?: 1 ?>" required>
      </div>

      <!-- Row 4: KM | City -->
      <div class="form-group">
        <label class="form-label">Kilometers Driven</label>
        <input type="number" name="kms_driven" class="form-input" placeholder="e.g. 15000"
               min="0" value="<?= $pKms ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">City</label>
        <select name="city" class="form-input" required>
          <option value="" disabled <?= !$pCity ? 'selected' : '' ?>>Select City</option>
          <?php foreach ($cities as $c): ?>
            <option value="<?= strtolower($c) ?>" <?= $pCity===strtolower($c)?'selected':'' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>

    <!-- ══ ACCIDENT HISTORY SECTION ══ -->
    <div class="form-section-label" style="margin-top:1.8rem"><span>Accident History</span></div>

    <div class="form-grid-2">

      <div class="form-group">
        <label class="form-label">Number of Accidents</label>
        <input type="number" name="accident_count" id="accCnt" class="form-input"
               placeholder="0" min="0" value="<?= $pAccCnt ?>" oninput="toggleAcc()">
      </div>

      <div class="form-group" id="accHistGroup" style="display:<?= (int)$pAccCnt>0?'flex':'none' ?>">
        <label class="form-label">Accident Severity</label>
        <select name="accident_history" class="form-input">
          <?php foreach ($acc_types as $a): ?>
            <option value="<?= $a ?>" <?= $pAccHist===$a?'selected':'' ?>><?= ucfirst($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>
    <div class="form-submit">
      <button type="submit" class="btn btn-primary btn-lg">⚡ &nbsp;Predict Price Now →</button>
    </div>
  </form>
</div>

<?php else: ?>

<!-- ══════════ RESULT ══════════ -->
<div class="glass-card anim-1">

  <div class="page-eyebrow"><span>Valuation Complete</span> // Random Forest · High Confidence</div>

  <div class="result-header">
    <div class="bike-id-badge">🏍</div>
    <div class="result-meta">
      <h2>
        <?= htmlspecialchars(ucwords($prediction['bike_name'] ?? 'Your Bike')) ?>
        <span style="color:var(--text-muted);font-family:'Cormorant Garamond',serif;font-style:italic;font-weight:300;font-size:1.35rem">
          &nbsp;by <?= htmlspecialchars(ucwords($prediction['brand'] ?? '')) ?>
        </span>
      </h2>
      <p class="result-sub">ML Model Valuation &nbsp;·&nbsp; Random Forest Regressor</p>
    </div>
  </div>

  <?php if ($prediction['ml_error']): ?>
    <div class="ml-offline">⚠ &nbsp;<?= htmlspecialchars($prediction['ml_error']) ?></div>
  <?php endif; ?>

  <!-- ★ PRICE CARDS ★ -->
  <div class="price-cards">

    <!-- Main valuation card -->
    <div class="price-box price-box--main">
      <?php if ($prediction['ml_price']): ?>
        <div class="ml-badge">ML Model Result</div>
      <?php endif; ?>
      <div class="price-label">Market Valuation</div>
      <div class="price-value">₹<?= number_format($prediction['final_price'] ?? 0) ?></div>
      <div class="price-note">Base price before accident adjustment</div>
    </div>

    <?php if ($prediction['has_accident'] && $prediction['ml_adjusted']): ?>
    <!-- Accident-adjusted card -->
    <div class="price-box price-box--adj">
      <div class="price-label">Post-Accident Price</div>
      <div class="price-value">₹<?= number_format($prediction['ml_adjusted']) ?></div>
      <div class="price-impact">↓ ₹<?= number_format(abs($prediction['ml_impact'] ?? 0)) ?> value loss</div>
      <div class="price-note">Adjusted for accident history</div>
    </div>
    <?php endif; ?>

  </div>

  <?php if (!empty($prediction['debug_payload'])): ?>
  <div style="margin-bottom:1.8rem">
    <details style="color:var(--text-muted)">
      <summary style="cursor:pointer;letter-spacing:1px;font-size:0.68rem;user-select:none">🔍 &nbsp;ML API Debug</summary>
      <pre style="margin-top:.7rem;background:rgba(0,0,0,.4);padding:1rem;border-radius:8px;overflow-x:auto;font-size:.7rem;line-height:1.6;border:1px solid rgba(201,168,76,0.12)"><?= htmlspecialchars($prediction['debug_payload']) ?></pre>
      <pre style="margin-top:.4rem;background:rgba(0,0,0,.4);padding:1rem;border-radius:8px;overflow-x:auto;font-size:.7rem;line-height:1.6;border:1px solid rgba(201,168,76,0.12)"><?= htmlspecialchars($prediction['debug_response']) ?></pre>
    </details>
  </div>
  <?php endif; ?>

  <div class="breakdown-title">Parameter Breakdown</div>
  <table>
    <thead><tr><th>Parameter</th><th>Value</th><th>Price Impact</th></tr></thead>
    <tbody>
    <?php foreach ($prediction['params'] as $p): ?>
      <tr>
        <td><?= htmlspecialchars($p['label']) ?></td>
        <td><?= htmlspecialchars($p['val']) ?></td>
        <td><span class="badge badge--<?= strtolower($p['impact']) ?>"><?= $p['impact'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="actions">
    <a href="predict.php?edit=1" class="btn-refine">
      <span class="arrow-ring">←</span><em>Refine Inputs</em>
    </a>
    <div class="actions-divider"></div>
    <a href="predict.php" class="btn btn-primary btn-lg">⚡ &nbsp;New Valuation →</a>
    <form action="auth.php" method="POST" style="margin:0;margin-left:auto">
      <input type="hidden" name="action" value="logout">
      <button class="btn btn-ghost">Logout</button>
    </form>
  </div>

</div>
<?php endif; ?>
</main>

<script>
const engineCC = {
  'classic 350':350,'classic 500':500,'bullet 350':346,'bullet 500':499,
  'thunderbird 350':346,'thunderbird 500':499,'himalayan':411,'meteor 350':349,
  'hunter 350':349,'super meteor 650':648,'continental gt 650':648,'interceptor 650':648,
  'fz-s v3':149,'fz25':249,'r15 v4':155,'mt-15':155,'r3':321,'fzs-fi':149,
  'fazer 25':249,'yzf r15':155,'ray zr':125,'fascino':125,'alpha':113,'sz-rr':153,
  'cb shine':124,'cb hornet 160r':163,'cb350':348,'cb500f':471,'cbr650r':649,
  'activa 6g':109,'unicorn':162,'livo':109,'sp 125':124,'shine sp':124,'cb200x':184,'nx200':184,
  'pulsar 150':149,'pulsar 180':178,'pulsar 220f':220,'pulsar ns200':199,'pulsar rs200':199,
  'dominar 400':373,'avenger 220':220,'ct100':102,'platina':102,'pulsar n250':250,
  'pulsar f250':250,'dominar 250':248,
  'apache rtr 160':159,'apache rtr 200':197,'apache rr 310':312,'jupiter':109,'ntorq 125':124,
  'raider 125':124,'ronin':225,'iqube electric':0,'star city+':109,'sport':99,'hlx 125':124,'radeon':109,
  'duke 200':199,'duke 250':248,'duke 390':373,'rc 200':199,'rc 390':373,
  'adventure 250':248,'adventure 390':373,'duke 125':124,'rc 125':124,
  'gixxer sf':155,'gixxer 250':249,'v-strom 650':645,'access 125':124,'burgman street':124,
  'intruder 150':154,'avenis 125':124,
  'ninja 300':296,'ninja 400':399,'ninja 650':649,'z650':649,'versys 650':649,'w175':177,'vulcan s':649,
  'splendor plus':97,'passion pro':97,'hf deluxe':97,'glamour':124,'xtreme 160r':163,
  'xpulse 200':199,'maestro edge':110,'destini 125':124,'super splendor':124,
  'tiger 660':660,'trident 660':660,
};

function updateBikeNames() {
  const brand = document.getElementById('brandSelect').value;
  const sel   = document.getElementById('bikeNameSelect');
  sel.innerHTML = '<option value="">Select from List or Type Below</option>';
  if (bikeNamesByBrand[brand]) {
    bikeNamesByBrand[brand].forEach(n => {
      const o = document.createElement('option');
      o.value = n.toLowerCase(); o.textContent = n; sel.appendChild(o);
    });
  }
  document.getElementById('engineCC').value = '';
  document.getElementById('model-hint').textContent = '';
}

function updateBikeNameFromSelect() {
  const v = document.getElementById('bikeNameSelect').value;
  if (v) document.getElementById('bikeNameInput').value = v;
}

function updateEngineCC() {
  const txt  = document.getElementById('bikeNameInput').value.toLowerCase().trim();
  const cc   = document.getElementById('engineCC');
  const hint = document.getElementById('cc-hint');
  if (!txt) { cc.value = ''; hint.textContent = ''; return; }
  if (engineCC[txt] !== undefined && engineCC[txt] > 0) {
    cc.value = engineCC[txt];
    hint.innerHTML = '✓ &nbsp;Auto-filled: <strong style="color:var(--gold)">' + engineCC[txt] + ' cc</strong>';
    hint.style.color = 'var(--gold-light)';
    cc.style.borderColor = 'rgba(201,168,76,0.8)';
    cc.style.boxShadow   = '0 0 0 2px rgba(201,168,76,0.15)';
    setTimeout(() => { cc.style.borderColor = ''; cc.style.boxShadow = ''; }, 2200);
  } else if (engineCC[txt] === 0) {
    cc.value = ''; hint.textContent = 'Electric — enter 0'; hint.style.color = 'var(--text-muted)';
  } else {
    hint.textContent = 'Enter CC manually'; hint.style.color = 'var(--text-muted)';
  }
}

function toggleAcc() {
  const n = parseInt(document.getElementById('accCnt').value) || 0;
  document.getElementById('accHistGroup').style.display = n > 0 ? 'flex' : 'none';
}
toggleAcc();

document.getElementById('bikeNameInput').addEventListener('input', updateEngineCC);

<?php if ($prediction): ?>
(function blockBack() {
  history.pushState({ bvBlocked: true }, '', location.href);
  window.addEventListener('popstate', function() {
    history.pushState({ bvBlocked: true }, '', location.href);
  });
})();
<?php endif; ?>

<?php if (!empty($pBrand)): ?>
(function prefill() {
  const savedBrand = <?= json_encode($pBrand) ?>;
  const savedBike  = <?= json_encode($pBikeName) ?>;
  const bSel = document.getElementById('brandSelect');
  for (let o of bSel.options) { if (o.text===savedBrand||o.value===savedBrand) { o.selected=true; break; } }
  updateBikeNames();
  document.getElementById('bikeNameInput').value = savedBike;
  const mSel = document.getElementById('bikeNameSelect');
  for (let o of mSel.options) { if (o.value===savedBike) { o.selected=true; break; } }
  const ccVal = document.getElementById('engineCC').value;
  if (ccVal && parseInt(ccVal) > 0) {
    const h = document.getElementById('cc-hint');
    h.innerHTML = '✓ &nbsp;Restored: <strong style="color:var(--gold)">' + ccVal + ' cc</strong>';
    h.style.color = 'var(--gold-light)';
  }
  setTimeout(updateEngineCC, 50);
})();
<?php endif; ?>
</script>
</body>
</html>

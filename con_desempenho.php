<?php
// ================================================================
// con_desempenho.php – Módulo “Consultor” (Relatório / Gráfico / Pizza)
// Stack: PHP 8+, PDO (MySQL/MariaDB), Bootstrap 5, Chart.js, Fetch API
// Requisitos del test: Formato moneda brasileña y fechas dd/mm/aaaa
// ================================================================

// ===== 1) CONFIGURACIÓN DB (AJUSTAR A TU ENTORNO) =================
$DB_HOST = $_ENV['DB_HOST'] ?? 'localhost';
$DB_NAME = $_ENV['DB_NAME'] ?? 'performance_comercial';
$DB_USER = $_ENV['DB_USER'] ?? 'root';
$DB_PASS = $_ENV['DB_PASS'] ?? '';
$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8";

function pdo(): PDO {
  static $pdo;
  global $dsn, $DB_USER, $DB_PASS;
  if (!$pdo) {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

// ===== 2) HELPERS ==================================================
function brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function pct_to_float($p) { // TOTAL_IMP_INC / COMISSAO_CN viene en % (ej: 23 => 0.23)
  if ($p === null) return 0.0;
  return (float)$p / 100.0;
}
function dt_dmy($ymd) { return $ymd ? date('d/m/Y', strtotime($ymd)) : ''; }
function start_of_month($ym) { // $ym en formato YYYY-MM
  return $ym . '-01';
}
function end_of_month($ym) {
  return date('Y-m-t', strtotime($ym . '-01'));
}

// ===== 3) ROUTER API (AJAX) ========================================
$action = $_GET['route'] ?? null;
if ($action) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($action === 'consultores') {
      echo json_encode(api_list_consultores());
    } elseif ($action === 'relatorio') {
      $consultores = $_POST['consultores'] ?? [];
      $fromYM = $_POST['fromYM'] ?? '';
      $toYM   = $_POST['toYM']   ?? '';
      echo json_encode(api_relatorio($consultores, $fromYM, $toYM));
    } elseif ($action === 'grafico') {
      $consultores = $_POST['consultores'] ?? [];
      $fromYM = $_POST['fromYM'] ?? '';
      $toYM   = $_POST['toYM']   ?? '';
      echo json_encode(api_grafico($consultores, $fromYM, $toYM));
    } elseif ($action === 'pizza') {
      $consultores = $_POST['consultores'] ?? [];
      $fromYM = $_POST['fromYM'] ?? '';
      $toYM   = $_POST['toYM']   ?? '';
      echo json_encode(api_pizza($consultores, $fromYM, $toYM));
    } else {
      http_response_code(404);
      echo json_encode(['error' => 'Ruta no encontrada']);
    }
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
  }
  exit;
}

// ===== 4) QUERIES / CÁLCULOS =======================================
function api_list_consultores(): array {
  // Tablas: CAO_USUARIO (U) + PERMISSAO_SISTEMA (P)
  // Join por CO_USUARIO y filtros: CO_SISTEMA = 1, IN_ATIVO = 'S', CO_TIPO_USUARIO IN (0,1,2)
  $sql = "
    SELECT U.CO_USUARIO, U.NO_USUARIO
    FROM CAO_USUARIO U
    JOIN PERMISSAO_SISTEMA P ON P.CO_USUARIO = U.CO_USUARIO
    WHERE P.CO_SISTEMA = 1
      AND P.IN_ATIVO = 'S'
      AND P.CO_TIPO_USUARIO IN (0,1,2)
    ORDER BY U.NO_USUARIO
  ";
  $rows = pdo()->query($sql)->fetchAll();
  return $rows;
}

function get_salario_bruto(string $co_usuario): float {
  // Costo Fijo: BRUT_SALARIO (valor fijo e invariable). Si hubiera múltiples, tomamos el más reciente.
  $stmt = pdo()->prepare("SELECT BRUT_SALARIO FROM CAO_SALARIO WHERE CO_USUARIO = ? ORDER BY DT_ALTERACAO DESC LIMIT 1");
  $stmt->execute([$co_usuario]);
  $row = $stmt->fetch();
  return $row ? (float)$row['BRUT_SALARIO'] : 0.0;
}

function query_faturas(string $co_usuario, string $fromDate, string $toDate): array {
  // Todas las facturas de OS del consultor en rango de fechas
  // CAO_FATURA (F) join CAO_OS (O) por CO_OS -> filtra O.CO_USUARIO
  $sql = "
    SELECT 
      F.DATA_EMISSAO,
      F.VALOR,
      F.TOTAL_IMP_INC,  -- %
      F.COMISSAO_CN     -- %
    FROM CAO_FATURA F
    JOIN CAO_OS O ON O.CO_OS = F.CO_OS
    WHERE O.CO_USUARIO = :USR
      AND F.DATA_EMISSAO BETWEEN :DF AND :DT
  ";
  $stmt = pdo()->prepare($sql);
  $stmt->execute([':USR' => $co_usuario, ':DF' => $fromDate, ':DT' => $toDate]);
  return $stmt->fetchAll();
}

function months_between_ym(string $fromYM, string $toYM): array {
  // Devuelve array de YYYY-MM desde fromYM hasta toYM
  $out = [];
  $cur = new DateTime($fromYM . '-01');
  $end = new DateTime($toYM . '-01');
  while ($cur <= $end) {
    $out[] = $cur->format('Y-m');
    $cur->modify('+1 month');
  }
  return $out;
}

function api_relatorio(array $consultores, string $fromYM, string $toYM): array {
  if (!$consultores) return ['rows' => [], 'totais' => [], 'msg' => 'Seleccione al menos 1 consultor.'];
  if (!$fromYM || !$toYM) return ['rows' => [], 'totais' => [], 'msg' => 'Seleccione rango de meses.'];

  $months = months_between_ym($fromYM, $toYM);
  $fromDate = start_of_month($fromYM);
  $toDate   = end_of_month($toYM);

  $rows = [];
  $totais = [];

  foreach ($consultores as $co_usuario) {
    $salario = get_salario_bruto($co_usuario);
    $fats = query_faturas($co_usuario, $fromDate, $toDate);

    // Agregar NO_USUARIO para mostrar
    $nameRow = pdo()->prepare("SELECT NO_USUARIO FROM CAO_USUARIO WHERE CO_USUARIO=?");
    $nameRow->execute([$co_usuario]);
    $no_usuario = ($nameRow->fetch()['NO_USUARIO'] ?? $co_usuario);

    // Inicializar acumuladores por mes
    $byMonth = [];
    foreach ($months as $ym) {
      $byMonth[$ym] = [
        'receita_liquida' => 0.0,
        'comissao' => 0.0,
        'custo_fixo' => $salario,
        'lucro' => 0.0,
      ];
    }

    foreach ($fats as $f) {
      $ym = date('Y-m', strtotime($f['DATA_EMISSAO']));
      if (!isset($byMonth[$ym])) continue; // fuera de rango mensual
      $valor = (float)$f['VALOR'];
      $imp   = pct_to_float($f['TOTAL_IMP_INC']);
      $com   = pct_to_float($f['COMISSAO_CN']);

      $liq = $valor - ($valor * $imp);
      $comissao = $liq * $com;

      $byMonth[$ym]['receita_liquida'] += $liq;
      $byMonth[$ym]['comissao']        += $comissao;
    }

    // Calcular lucro por mes y armar filas
    foreach ($months as $ym) {
      $r = $byMonth[$ym];
      $lucro = $r['receita_liquida'] - ($r['custo_fixo'] + $r['comissao']);
      $r['lucro'] = $lucro;
      $rows[] = [
        'consultor' => $no_usuario,
        'co_usuario' => $co_usuario,
        'mes' => $ym,
        'mes_label' => date('m/Y', strtotime($ym . '-01')),
        'receita_liquida' => $r['receita_liquida'],
        'custo_fixo' => $r['custo_fixo'],
        'comissao' => $r['comissao'],
        'lucro' => $r['lucro'],
      ];
    }
  }

  // Totales generales por mes (opcional)
  foreach ($rows as $r) {
    $k = $r['mes'];
    if (!isset($totais[$k])) {
      $totais[$k] = ['receita_liquida'=>0,'custo_fixo'=>0,'comissao'=>0,'lucro'=>0];
    }
    $totais[$k]['receita_liquida'] += $r['receita_liquida'];
    $totais[$k]['custo_fixo']      += $r['custo_fixo'];
    $totais[$k]['comissao']        += $r['comissao'];
    $totais[$k]['lucro']           += $r['lucro'];
  }

  return ['rows' => $rows, 'totais' => $totais];
}

function api_grafico(array $consultores, string $fromYM, string $toYM): array {
  if (!$consultores) return ['series' => [], 'media_custo_fixo' => 0];
  $fromDate = start_of_month($fromYM);
  $toDate   = end_of_month($toYM);

  $series = [];
  $custos = [];

  foreach ($consultores as $co_usuario) {
    $salario = get_salario_bruto($co_usuario);
    $custos[] = $salario;

    // Nombre consultor
    $nameRow = pdo()->prepare("SELECT NO_USUARIO FROM CAO_USUARIO WHERE CO_USUARIO=?");
    $nameRow->execute([$co_usuario]);
    $nome = ($nameRow->fetch()['NO_USUARIO'] ?? $co_usuario);

    // Sumar receita líquida total periodo
    $fats = query_faturas($co_usuario, $fromDate, $toDate);
    $sumLiq = 0.0;
    foreach ($fats as $f) {
      $valor = (float)$f['VALOR'];
      $imp   = pct_to_float($f['TOTAL_IMP_INC']);
      $sumLiq += $valor - ($valor * $imp);
    }
    $series[] = ['consultor' => $nome, 'valor' => $sumLiq];
  }

  $media = count($custos) ? array_sum($custos)/count($custos) : 0.0;
  return ['series' => $series, 'media_custo_fixo' => $media];
}

function api_pizza(array $consultores, string $fromYM, string $toYM): array {
  if (!$consultores) return ['series' => []];
  $fromDate = start_of_month($fromYM);
  $toDate   = end_of_month($toYM);

  $series = [];
  $total = 0.0;

  foreach ($consultores as $co_usuario) {
    // Nombre consultor
    $nameRow = pdo()->prepare("SELECT NO_USUARIO FROM CAO_USUARIO WHERE CO_USUARIO=?");
    $nameRow->execute([$co_usuario]);
    $nome = ($nameRow->fetch()['NO_USUARIO'] ?? $co_usuario);

    $fats = query_faturas($co_usuario, $fromDate, $toDate);
    $sumLiq = 0.0;
    foreach ($fats as $f) {
      $valor = (float)$f['VALOR'];
      $imp   = pct_to_float($f['TOTAL_IMP_INC']);
      $sumLiq += $valor - ($valor * $imp);
    }
    $series[] = ['consultor' => $nome, 'valor' => $sumLiq];
    $total += $sumLiq;
  }

  // Calcular %
  foreach ($series as &$s) {
    $s['percent'] = $total > 0 ? ($s['valor'] / $total) * 100.0 : 0.0;
  }
  return ['series' => $series, 'total' => $total];
}

// ===== 5) HTML UI ==================================================
?>
<!doctype html>
<html lang="es" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consultor – Desempeño</title>
  <!-- Fonts & Icons -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <!-- Bootstrap & Charts -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    :root{
      --bg: #0b1220;        /* fondo base */
      --bg-elev: #0f172a;   /* elevación */
      --card: #0f172a;
      --card-2:#111827;
      --muted:#94a3b8;
      --text:#e2e8f0;
      --primary:#2563eb;
      --primary-2:#1d4ed8;
      --border:#1f2937;
      --shadow: 0 10px 30px rgba(0,0,0,.3);
      --radius: 16px;
    }
    *{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif}
    body{background: radial-gradient(1200px 600px at 10% -10%, rgba(37,99,235,.12), transparent 60%),
                      radial-gradient(1200px 600px at 110% 10%, rgba(16,185,129,.08), transparent 60%), var(--bg);
         color: var(--text)}
    .glass{background: linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
           border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow)}
    .navbar{background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.01)); border-bottom:1px solid var(--border)}
    .brand-mark{display:flex;align-items:center;gap:.6rem}
    .brand-dot{width:10px;height:10px;border-radius:50%;background:linear-gradient(145deg,var(--primary),#22c55e)}
    .subtle{color:var(--muted)}
    .btn-primary{background:var(--primary);border-color:var(--primary)}
    .btn-primary:hover{background:var(--primary-2);border-color:var(--primary-2)}
    .btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text)}
    .btn-ghost:hover{background:#0b1220}
    .card{background:var(--card-2);border:1px solid var(--border);border-radius:var(--radius)}
    .card-gradient{background: radial-gradient(600px 300px at 0% 0%, rgba(37,99,235,.10), transparent 50%), var(--card-2)}
    .chip{display:inline-flex;align-items:center;gap:.5rem;padding:.4rem .65rem;border:1px solid var(--border);border-radius:999px;background:#0b1220;color:#cbd5e1}
    .form-control,.form-select{background:#0b1220;color:var(--text);border-color:var(--border)}
    .form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 .2rem rgba(37,99,235,.15)}
    .table{--bs-table-bg:transparent}
    .table thead th{color:#94a3b8;border-bottom-color:var(--border);position:sticky;top:0;background:var(--card-2)}
    .table tbody td{border-color:rgba(148,163,184,.15)}
    td.num{text-align:right}
    .empty{display:flex;flex-direction:column;align-items:center;gap:.5rem;color:#93a3b8;padding:2rem;border:1px dashed var(--border);border-radius:var(--radius)}
    .toolbar{gap:.5rem;flex-wrap:wrap}
    .shadow-soft{box-shadow: 0 12px 40px rgba(2,6,23,.4)}
    .sticky-tools{position:sticky;top:1rem;z-index:10}
    .footer-note{color:#64748b}
    .skeleton{background:linear-gradient(90deg,#0b1220,#0e1627,#0b1220);background-size:200% 100%;animation:skeleton 1.1s infinite}
    @keyframes skeleton{0%{background-position:200% 0}100%{background-position:-200% 0}}

    /* Mantiene el canvas de la pizza cuadrado y centrado */
    .chart-square{
      position: relative;
      width: 100%;
      max-width: 420px;   /* ajusta a gusto */
      margin: 0 auto;     /* centra el gráfico */
    }
    .chart-square::before{
      content: "";
      display: block;
      padding-top: 100%;  /* relación 1:1 */
    }
    .chart-square > canvas{
      position: absolute;
      inset: 0;
    }
  </style>
</head>
<body>
  <!-- NAVBAR -->
  <nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
      <div class="brand-mark">
        <span class="brand-dot"></span>
        <a class="navbar-brand text-white fw-semibold" href="#">Desempeño por Consultor</a>
        <span class="ms-2 subtle">Panel</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button id="themeToggle" class="btn btn-ghost btn-sm" type="button" title="Tema claro/oscuro">
          <i class="bi bi-moon-stars"></i>
        </button>
        <a class="btn btn-primary btn-sm" href="#relatorioCard"><i class="bi bi-graph-up-arrow me-1"></i>Ver resultados</a>
      </div>
    </div>
  </nav>

  <main class="container my-4">
    <div class="row g-4">
      <!-- FILTROS -->
      <div class="col-lg-4">
        <div class="card card-gradient p-3 shadow-soft sticky-tools">
          <div class="d-flex align-items-start justify-content-between">
            <div>
              <h2 class="h5 mb-1">Filtros</h2>
              <div class="subtle">Selecciona consultores y período</div>
            </div>
            <!-- Badge eliminado a pedido -->
          </div>
          <hr class="border-secondary-subtle">

          <div class="mb-3">
            <label class="form-label">Consultores</label>
            <select id="consultores" class="form-select" multiple size="8" aria-label="Selecciona consultores"></select>
            <div class="form-text subtle">Usa Ctrl/Cmd para selección múltiple</div>
          </div>

          <div class="row g-3">
            <div class="col-6">
              <label class="form-label">Mes inicial</label>
              <input id="fromYM" type="month" class="form-control" aria-label="Mes inicial">
            </div>
            <div class="col-6">
              <label class="form-label">Mes final</label>
              <input id="toYM" type="month" class="form-control" aria-label="Mes final">
            </div>
          </div>

          <div class="d-flex toolbar mt-3">
            <button id="btnRelatorio" class="btn btn-primary"><i class="bi bi-layout-text-sidebar-reverse me-1"></i>Relatório</button>
            <button id="btnGrafico" class="btn btn-ghost"><i class="bi bi-bar-chart-line me-1"></i>Gráfico</button>
            <button id="btnPizza" class="btn btn-ghost"><i class="bi bi-pie-chart me-1"></i>Pizza</button>
          </div>
          <div class="mt-3">
            <span class="chip"><i class="bi bi-info-circle"></i> Fechas dd/mm/aaaa · BRL</span>
          </div>
        </div>
      </div>

      <!-- CONTENIDO -->
      <div class="col-lg-8">
        <!-- RELATORIO -->
        <section id="relatorioCard" class="card p-3 d-none">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
              <h3 class="h5 mb-0">Relatório</h3>
              <span class="subtle">Receita líquida, costo fijo, comisión y lucro por mes</span>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-ghost btn-sm" onclick="copyToClipboard()"><i class="bi bi-clipboard-check"></i> Copiar</button>
              <button class="btn btn-ghost btn-sm" onclick="exportCSV()"><i class="bi bi-filetype-csv"></i> CSV</button>
            </div>
          </div>
          <div class="table-responsive rounded-3" style="max-height: 52vh;">
            <table class="table table-hover align-middle" id="tblRelatorio">
              <thead>
                <tr>
                  <th>Consultor</th>
                  <th>Mes</th>
                  <th class="text-end">Receita Líquida</th>
                  <th class="text-end">Custo Fixo</th>
                  <th class="text-end">Comissão</th>
                  <th class="text-end">Lucro</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div id="totais" class="mt-3 d-flex flex-wrap gap-2"></div>
        </section>

        <!-- GRÁFICO -->
        <section id="graficoCard" class="card p-3 d-none">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
              <h3 class="h5 mb-0">Gráfico</h3>
              <span class="subtle">Barras: receita líquida por consultor · Línea: costo fijo medio</span>
            </div>
          </div>
          <canvas id="barChart" height="120"></canvas>
        </section>

        <!-- PIZZA -->
        <section id="pizzaCard" class="card p-3 d-none">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
              <h3 class="h5 mb-0">Participación de Receita Líquida</h3>
              <span class="subtle">Porcentaje por consultor sobre el total del período</span>
            </div>
          </div>
          <div class="chart-square">
            <canvas id="pieChart"></canvas>
          </div>
          <div id="pieLegend" class="mt-3 d-flex flex-wrap gap-2"></div>
        </section>

        <!-- EMPTY STATE -->
        <section id="emptyState" class="empty glass">
          <div style="font-size:2rem">📊</div>
          <div class="fw-semibold">Aún no hay datos</div>
          <div class="subtle">Selecciona consultores y un rango de meses y luego elige Relatório, Gráfico o Pizza.</div>
        </section>
      </div>
    </div>

    <p class="mt-4 text-center footer-note">Formato monetario BR y fechas dd/mm/aaaa</p>
  </main>

  <!-- Toasts -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div id="toast" class="toast text-bg-dark border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="toast-body" id="toastBody">Listo</div>
    </div>
  </div>

<script>
// ======== UTILIDADES FRONT =========
const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
function showToast(msg){ const el=document.getElementById('toast'); document.getElementById('toastBody').textContent=msg; new bootstrap.Toast(el,{delay:2200}).show(); }
function getSelectedConsultores(){ return Array.from(document.querySelectorAll('#consultores option:checked')).map(o=>o.value); }
function ensureRange(){ const f=fromYM.value, t=toYM.value; if(!f||!t){ showToast('Selecciona mes inicial y final'); return null;} if(f>t){ showToast('El mes inicial no puede ser mayor al final'); return null;} return {fromYM:f,toYM:t}; }
function setLoading(on){ [btnRelatorio,btnGrafico,btnPizza].forEach(b=>b.disabled=!!on); document.body.style.cursor= on?'progress':'default'; }
function showOnly(which){ ['relatorio','grafico','pizza'].forEach(id=>document.getElementById(id+'Card').classList.toggle('d-none', which!==id)); document.getElementById('emptyState').classList.toggle('d-none', !!which); }

// Tema claro/oscuro
const themeToggle=document.getElementById('themeToggle');
if(localStorage.getItem('theme')) document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme'));
function toggleTheme(){ const cur=document.documentElement.getAttribute('data-bs-theme')||'dark'; const next= cur==='dark'?'light':'dark'; document.documentElement.setAttribute('data-bs-theme', next); localStorage.setItem('theme', next); themeToggle.innerHTML = next==='dark'?'<i class="bi bi-moon-stars"></i>':'<i class="bi bi-sun"></i>'; }
themeToggle.addEventListener('click', toggleTheme);

// ======== CARGA DE CONSULTOR(ES) =========
async function loadConsultores(){
  try{
    const res = await fetch('?route=consultores');
    const list = await res.json();
    consultores.innerHTML = '';
    list.forEach(r=>{ const opt=document.createElement('option'); opt.value=r.CO_USUARIO; opt.textContent=r.NO_USUARIO; consultores.appendChild(opt); });
  }catch(e){ showToast('Error al cargar consultores'); }
}

// ======== RELATORIO =========
let relatorioCache = [];
async function runRelatorio(){
  const consultores = getSelectedConsultores();
  const range = ensureRange(); if(!range) return; if(!consultores.length){ showToast('Selecciona al menos 1 consultor'); return; }
  setLoading(true);
  try{
    const body = new URLSearchParams(); consultores.forEach(c=>body.append('consultores[]', c)); body.append('fromYM', range.fromYM); body.append('toYM', range.toYM);
    const res = await fetch('?route=relatorio', { method:'POST', body });
    const data = await res.json(); relatorioCache = (data && data.rows) ? data.rows : [];
    const tbody = document.querySelector('#tblRelatorio tbody'); tbody.innerHTML='';
    (relatorioCache).forEach(r=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${r.consultor}</td>
        <td>${r.mes_label}</td>
        <td class="num">${brl.format(r.receita_liquida)}</td>
        <td class="num">${brl.format(r.custo_fixo)}</td>
        <td class="num">${brl.format(r.comissao)}</td>
        <td class="num">${brl.format(r.lucro)}</td>`;
      tbody.appendChild(tr);
    });
    // Totales por mes
    const totDiv = document.getElementById('totais'); const tot = (data && data.totais) ? data.totais : {}; let html = '';
    Object.keys(tot).sort().forEach(m=>{ html += `<span class="chip">Mes ${m.substring(5,7)}/${m.substring(0,4)} · Receita: ${brl.format(tot[m].receita_liquida)} · Custo Fixo: ${brl.format(tot[m].custo_fixo)} · Comissão: ${brl.format(tot[m].comissao)} · Lucro: ${brl.format(tot[m].lucro)}</span>`; });
    totDiv.innerHTML = html || '<span class="subtle">Sin totales</span>';
    showOnly('relatorio');
    showToast('Relatório generado');
  }catch(e){ showToast('Error al generar relatório'); }
  finally{ setLoading(false); }
}

function exportCSV(){
  if(!relatorioCache.length){ showToast('Primero genera el relatório'); return; }
  const headers = ['Consultor','Mes','Receita Liquida','Custo Fixo','Comissao','Lucro'];
  const lines = [headers.join(',')];
  relatorioCache.forEach(r=>{ lines.push([`"${r.consultor}"`,`"${r.mes_label}"`,r.receita_liquida.toFixed(2).replace('.',','),r.custo_fixo.toFixed(2).replace('.',','),r.comissao.toFixed(2).replace('.',','),r.lucro.toFixed(2).replace('.',',')].join(',')); });
  const blob = new Blob(["\uFEFF"+lines.join('\n')], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob); const a = Object.assign(document.createElement('a'), { href:url, download:'relatorio.csv' }); a.click(); URL.revokeObjectURL(url);
  showToast('CSV exportado');
}

function copyToClipboard(){
  if(!relatorioCache.length){ showToast('Nada para copiar'); return; }
  const text = relatorioCache.map(r=>`${r.consultor}\t${r.mes_label}\t${r.receita_liquida}\t${r.custo_fixo}\t${r.comissao}\t${r.lucro}`).join('\n');
  navigator.clipboard.writeText(text).then(()=>showToast('Copiado al portapapeles'));
}

// ======== GRÁFICO =========
let barChart; let pieChart;
async function runGrafico(){
  const consultores = getSelectedConsultores(); const range = ensureRange(); if(!range) return; if(!consultores.length){ showToast('Selecciona al menos 1 consultor'); return; }
  setLoading(true);
  try{
    const body = new URLSearchParams(); consultores.forEach(c=>body.append('consultores[]', c)); body.append('fromYM', range.fromYM); body.append('toYM', range.toYM);
    const res = await fetch('?route=grafico', { method:'POST', body }); const data = await res.json();
    const labels = (data.series||[]).map(s=>s.consultor); const values = (data.series||[]).map(s=>s.valor); const media = data.media_custo_fixo || 0;
    if(barChart){ barChart.destroy(); }
    const ctx = document.getElementById('barChart');
    barChart = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [ { label: 'Receita Líquida', data: values }, { label: 'Custo Fixo Médio', data: labels.map(()=>media), type:'line', yAxisID:'y' } ] },
      options: { responsive: true, scales: { y: { beginAtZero:true, max: 32000 } }, plugins: { legend: {labels:{color:'#cbd5e1'}}, tooltip: { callbacks: { label: (c)=> ` ${c.dataset.label}: ${brl.format(c.raw)}` } } } }
    });
    showOnly('grafico'); showToast('Gráfico listo');
  }catch(e){ showToast('Error al generar gráfico'); }
  finally{ setLoading(false); }
}

// ======== PIZZA (con círculo perfecto y manejo sin datos) =========
async function runPizza(){
  const consultores = getSelectedConsultores(); const range = ensureRange(); if(!range) return; if(!consultores.length){ showToast('Selecciona al menos 1 consultor'); return; }
  setLoading(true);
  try{
    const body = new URLSearchParams(); consultores.forEach(c=>body.append('consultores[]', c)); body.append('fromYM', range.fromYM); body.append('toYM', range.toYM);
    const res = await fetch('?route=pizza', { method:'POST', body }); const data = await res.json();
    const labels = (data.series||[]).map(s=>s.consultor); const values = (data.series||[]).map(s=>s.valor); const perc   = (data.series||[]).map(s=>s.percent);

    const pieWrap = document.getElementById('pieChart').parentElement;
    const legend  = document.getElementById('pieLegend');

    const totalValue = values.reduce((a,b)=>a+b, 0);
    if (totalValue <= 0) {
      if(pieChart) pieChart.destroy();
      const ctx = document.getElementById('pieChart');
      pieChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: ['Sin datos'], datasets: [{ data: [1] }] },
        options: { responsive: true, maintainAspectRatio: true, aspectRatio: 1 }
      });
      legend.innerHTML = '<span class="chip">Sin datos en el período seleccionado</span>';
      showOnly('pizza'); showToast('No hay receita líquida en el período');
      return;
    } else {
      pieWrap.style.display = '';
    }

    if(pieChart){ pieChart.destroy(); }
    const ctx = document.getElementById('pieChart');
    pieChart = new Chart(ctx, {
      type: 'pie',
      data: { labels, datasets: [{ data: perc }] },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 1,
        plugins: {
          legend:{labels:{color:'#cbd5e1'}},
          tooltip: { callbacks: { label:(c)=>` ${c.label}: ${c.raw.toFixed(2)}%` } }
        }
      }
    });

    legend.innerHTML = labels.map((l,i)=>`<span class="chip">${l}: ${perc[i].toFixed(2)}% (${brl.format(values[i])})</span>`).join(' ');
    showOnly('pizza'); showToast('Pizza lista');
  }catch(e){ showToast('Error al generar pizza'); }
  finally{ setLoading(false); }
}

// ======== INIT =========
btnRelatorio.addEventListener('click', runRelatorio);
btnGrafico.addEventListener('click', runGrafico);
btnPizza.addEventListener('click', runPizza);
(function(){ const now = new Date(); const ym = now.toISOString().slice(0,7); fromYM.value = ym; toYM.value = ym; loadConsultores(); })();
</script>
</body>
</html>

<?php
// index.php - single-page UI. api.php handles uploads/parsing.
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Excel Dashboard (PHP)</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body{font-family:Inter,system-ui,Arial; background:#f6f8fb; margin:0; padding:20px}
    .wrap{max-width:1100px;margin:0 auto}
    header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
    h1{font-size:20px;margin:0}
    .card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 6px 22px rgba(18,38,63,0.08);}
    .controls{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    select,input[type=file],button{padding:8px 10px;border-radius:8px;border:1px solid #d1d5db}
    .grid{display:grid;grid-template-columns:1fr 420px;gap:16px;margin-top:16px}
    #chartWrapper{height:360px}
    .small{font-size:13px;color:#374151}
    .stat{background:#eef6ff;padding:10px;border-radius:8px;margin-top:10px}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:6px;border-bottom:1px solid #eee;text-align:left}
  </style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>📊 Excel Dashboard (PHP backend)</h1>
    <div class="small">Upload .xlsx — parsed on server with PhpSpreadsheet</div>
  </header>

  <div class="card">
    <div style="display:flex;gap:12px;align-items:center;">
      <input id="fileInput" type="file" accept=".xlsx,.xls" />
      <button id="uploadBtn">Upload & Parse</button>
      <span id="status" class="small"></span>
    </div>

    <div class="controls">
      <label>Sheet: <select id="sheetSelect"></select></label>
      <label>Filter City: <select id="cityFilter"><option value="all">All</option></select></label>
      <label>Filter City Type: <select id="cityTypeFilter"><option value="all">All</option></select></label>
      <label>Month: <select id="monthSelect"></select></label>
      <label>Aggregate by: <select id="aggSelect"><option value="month">Month</option><option value="city">City</option></select></label>
    </div>

    <div class="grid">
      <div>
        <div class="card" id="chartCard">
          <h3 style="margin-top:0">Timeline / Bar chart</h3>
          <div id="chartWrapper"><canvas id="timelineChart"></canvas></div>
          <div id="maxMin" class="stat"></div>
        </div>

        <div class="card" style="margin-top:12px">
          <h3 style="margin-top:0">Rows preview</h3>
          <div id="tablePreview" style="max-height:240px;overflow:auto"></div>
        </div>
      </div>

      <div>
        <div class="card">
          <h3 style="margin-top:0">Controls & Info</h3>
          <div class="small">Use the dropdowns above to choose sheet, filter by City / City_Type and pick a Month. The timeline chart shows aggregated Projected_Enrollments across months by default. Choosing "Aggregate by: City" and selecting a Month will show a bar chart of enrollments per city for that month.</div>
          <hr />
          <div style="margin-top:8px">
            <strong>Detected columns</strong>
            <ul id="colsList"></ul>
          </div>
        </div>

        <div class="card" style="margin-top:12px">
          <h3 style="margin-top:0">Download</h3>
          <button id="downloadCsv">Download filtered CSV</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let parsed = { sheets: {}, metadata: {} };
let chart;

const fileInput = document.getElementById('fileInput');
const uploadBtn = document.getElementById('uploadBtn');
const status = document.getElementById('status');

uploadBtn.addEventListener('click', ()=>{
  if(!fileInput.files.length){ alert('Select an Excel file first'); return; }
  uploadFile(fileInput.files[0]);
});

async function uploadFile(file){
  status.textContent = 'Uploading...';
  const fd = new FormData();
  fd.append('excel', file);
  try{
    const res = await fetch('api.php?action=upload', { method: 'POST', body: fd });
    const data = await res.json();
    if(data.success){
      parsed = data;
      status.textContent = 'Parsed ✔️';
      populateUI();
    } else {
      status.textContent = 'Error: ' + (data.error||'unknown');
    }
  }catch(e){ status.textContent = 'Upload failed'; console.error(e); }
}

function populateUI(){
  const sheetSelect = document.getElementById('sheetSelect');
  sheetSelect.innerHTML = '';
  parsed.sheets_order.forEach(name => {
    const opt = document.createElement('option'); opt.value = name; opt.textContent = name; sheetSelect.appendChild(opt);
  });
  sheetSelect.onchange = renderSheet;
  document.getElementById('downloadCsv').onclick = downloadFilteredCSV;
  renderSheet();
}

function renderSheet(){
  const sheetName = document.getElementById('sheetSelect').value || parsed.sheets_order[0];
  const sheet = parsed.sheets[sheetName];
  // columns
  const colsList = document.getElementById('colsList'); colsList.innerHTML = '';
  sheet.columns.forEach(c => { const li = document.createElement('li'); li.textContent = c; colsList.appendChild(li); });

  // populate city and city type filters
  const cityFilter = document.getElementById('cityFilter'); cityFilter.innerHTML = '<option value="all">All</option>';
  const cityTypeFilter = document.getElementById('cityTypeFilter'); cityTypeFilter.innerHTML = '<option value="all">All</option>';
  const monthSelect = document.getElementById('monthSelect'); monthSelect.innerHTML = '';

  const cities = new Set(); const types = new Set(); const months = new Set();
  sheet.rows.forEach(r=>{ if(r.City) cities.add(r.City); if(r.City_Type) types.add(r.City_Type); if(r.Month) months.add(r.Month); });
  [...cities].sort().forEach(c=>{ const o=document.createElement('option'); o.value=c; o.textContent=c; cityFilter.appendChild(o); });
  [...types].sort().forEach(c=>{ const o=document.createElement('option'); o.value=c; o.textContent=c; cityTypeFilter.appendChild(o); });
  [...months].sort().forEach(m=>{ const o=document.createElement('option'); o.value=m; o.textContent=m; monthSelect.appendChild(o); });

  cityFilter.onchange = updateAll; cityTypeFilter.onchange = updateAll; monthSelect.onchange = updateAll; document.getElementById('aggSelect').onchange = updateAll;

  // preview table
  renderPreview(sheet);
  updateAll();
}

function renderPreview(sheet){
  const div = document.getElementById('tablePreview');
  const cols = sheet.columns;
  let html = '<table><thead><tr>' + cols.map(c=>`<th>${c}</th>`).join('') + '</tr></thead><tbody>';
  sheet.rows.slice(0,200).forEach(r=>{ html += '<tr>' + cols.map(c=>`<td>${r[c]===undefined? '': r[c]}</td>`).join('') + '</tr>'; });
  html += '</tbody></table>';
  div.innerHTML = html;
}

function getFilteredRows(){
  const sheetName = document.getElementById('sheetSelect').value || parsed.sheets_order[0];
  const sheet = parsed.sheets[sheetName];
  const city = document.getElementById('cityFilter').value;
  const ctype = document.getElementById('cityTypeFilter').value;
  return sheet.rows.filter(r=>{
    if(city !== 'all' && (r.City||'') !== city) return false;
    if(ctype !== 'all' && (r.City_Type||'') !== ctype) return false;
    return true;
  });
}

function updateAll(){
  const rows = getFilteredRows();
  const agg = document.getElementById('aggSelect').value;
  const monthSel = document.getElementById('monthSelect').value;

  // build timeline by month (sum of Projected_Enrollments)
  const byMonth = {};
  rows.forEach(r=>{ const m = r.Month; const v = parseFloat(r.Projected_Enrollments)||0; if(!byMonth[m]) byMonth[m]=0; byMonth[m]+=v; });
  const months = Object.keys(byMonth).sort();
  const monthVals = months.map(m=>byMonth[m]);

  // prepare chart
  const ctx = document.getElementById('timelineChart').getContext('2d');
  if(chart) chart.destroy();
  if(agg === 'month'){
    chart = new Chart(ctx, { type: 'bar', data:{ labels: months, datasets:[{ label:'Total Projected Enrollments', data:monthVals }] }, options:{ responsive:true } });
  } else {
    // aggregate by city for selected month
    const rowsForMonth = rows.filter(r=> (r.Month||'') === monthSel );
    const byCity = {};
    rowsForMonth.forEach(r=>{ const c=r.City||'Unknown'; byCity[c] = (byCity[c]||0) + (parseFloat(r.Projected_Enrollments)||0); });
    const cities = Object.keys(byCity).sort();
    const vals = cities.map(c=>byCity[c]);
    chart = new Chart(ctx, { type:'bar', data:{ labels:cities, datasets:[{ label:`Projected Enrollments (${monthSel})`, data:vals }] }, options:{ indexAxis:'y', responsive:true } });
  }

  // compute max/min for month select - show which city got max/min in selected month
  const monthChosen = monthSel || Object.keys(byMonth)[0];
  if(monthChosen){
    const rowsFor = rows.filter(r=> (r.Month||'') === monthChosen );
    const grouped = {};
    rowsFor.forEach(r=>{ const c=r.City||'Unknown'; grouped[c] = (grouped[c]||0) + (parseFloat(r.Projected_Enrollments)||0); });
    const entries = Object.entries(grouped);
    if(entries.length){
      entries.sort((a,b)=>b[1]-a[1]);
      const max = entries[0];
      const min = entries[entries.length-1];
      document.getElementById('maxMin').innerHTML = `<strong>Month:</strong> ${monthChosen}<br><strong>Max:</strong> ${max[0]} => ${max[1]}<br><strong>Min:</strong> ${min[0]} => ${min[1]}`;
    } else {
      document.getElementById('maxMin').innerHTML = 'No rows for selected month (after filters).';
    }
  }
}

function downloadFilteredCSV(){
  const rows = getFilteredRows();
  if(!rows.length){ alert('No rows to download'); return; }
  const sheetName = document.getElementById('sheetSelect').value || parsed.sheets_order[0];
  const cols = parsed.sheets[sheetName].columns;
  let csv = cols.join(',') + '\n';
  rows.forEach(r=>{ csv += cols.map(c=> '"'+String(r[c]||'').replace(/"/g,'""')+'"').join(',') + '\n'; });
  const blob = new Blob([csv], {type:'text/csv'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = 'filtered.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
}
</script>
</body>
</html>

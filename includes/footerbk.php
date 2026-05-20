  </div><!-- .content -->
</div><!-- .main -->

<!-- ══ BOTTOM NAV MÓVIL ══ -->
<nav class="mob-bottom-nav" id="mobBottomNav" style="display:none" aria-label="Navegación móvil">
<?php
$_bnav = [
  ['icon'=>'⊞', 'label'=>'Inicio',     'page'=>'dashboard'],
  ['icon'=>'🗓️','label'=>'Agenda',     'page'=>'calendario'],
  ['icon'=>'🐾', 'label'=>'Mascotas',   'page'=>'mascotas'],
  ['icon'=>'📋', 'label'=>'Historia',   'page'=>'historial'],
  ['icon'=>'☰',  'label'=>'Más',        'page'=>'_menu'],
];
foreach ($_bnav as $_bn):
  $_active  = ($_bn['page'] !== '_menu') && ($page === $_bn['page']);
  $_href    = $_bn['page'] === '_menu' ? 'javascript:void(0)' : BASE_URL.'/index.php?p='.$_bn['page'];
  $_onclick = $_bn['page'] === '_menu' ? ' onclick="openMobMenu()"' : '';
  $_badge   = ($_bn['page']==='citas') ? ($citas_hoy ?? 0) : 0;
?>
<a href="<?= $_href ?>" class="mob-nav-item <?= $_active?'active':'' ?>"<?= $_onclick ?>>
  <?php if ($_badge > 0): ?><span class="mob-nav-badge"><?= $_badge ?></span><?php endif; ?>
  <span class="mob-nav-icon"><?= $_bn['icon'] ?></span>
  <span><?= $_bn['label'] ?></span>
</a>
<?php endforeach; ?>
</nav>

<script>
/* ── Mobile drawer ── */
function openMobMenu(){
  var sb=document.getElementById('mainSidebar');
  var ov=document.getElementById('mobOverlay');
  if(sb) sb.classList.add('mob-open');
  if(ov) ov.classList.add('active');
}
function closeMobMenu(){
  var sb=document.getElementById('mainSidebar');
  var ov=document.getElementById('mobOverlay');
  if(sb) sb.classList.remove('mob-open');
  if(ov) ov.classList.remove('active');
}

document.addEventListener('DOMContentLoaded',function(){
  /* Cerrar drawer al navegar */
  var sb=document.getElementById('mainSidebar');
  if(sb) sb.querySelectorAll('a').forEach(function(el){
    el.addEventListener('click',function(){
      if(window.innerWidth<=768) closeMobMenu();
    });
  });
});

/* Swipe */
var _swX=0;
document.addEventListener('touchstart',function(e){_swX=e.touches[0].clientX;},{passive:true});
document.addEventListener('touchend',function(e){
  var dx=e.changedTouches[0].clientX-_swX;
  var sb=document.getElementById('mainSidebar');
  if(sb&&sb.classList.contains('mob-open')&&dx<-60) closeMobMenu();
  if(sb&&!sb.classList.contains('mob-open')&&_swX<24&&dx>60) openMobMenu();
},{passive:true});

/* ── Veterinaria menu toggle ── */
function toggleVetMenu(){
  var m=document.getElementById('vet-submenu');
  var c=document.getElementById('vet-caret');
  var open=m.style.display==='block';
  m.style.display=open?'none':'block';
  if(c) c.textContent=open?'∨':'∧';
}

/* ── Helper global ── */
function copyToClipboard(text,btn){
  navigator.clipboard.writeText(text).then(function(){
    var orig=btn.textContent; btn.textContent='✓ Copiado';
    setTimeout(function(){btn.textContent=orig;},2000);
  });
}

/* ── Buscador global con autocomplete ── */
var _gsApi  = '<?= BASE_URL ?>/api/autocomplete.php';
var _gsBase = '<?= BASE_URL ?>';
var _gsEi   = {perro:'🐕',gato:'🐈',conejo:'🐰',ave:'🐦',reptil:'🦎',roedor:'🐭',otro:'🐾'};
var _gsTimer= null;
var _gsFocus= -1;

function gsAutoComplete(val){
  clearTimeout(_gsTimer);
  var drop=document.getElementById('gsDropdown');
  if(!val||val.trim().length<2){drop.style.display='none';_gsFocus=-1;return;}
  _gsTimer=setTimeout(function(){
    fetch(_gsApi+'?q='+encodeURIComponent(val.trim()))
      .then(function(r){return r.ok?r.json():null;})
      .then(function(d){if(d) gsRender(d,val.trim());})
      .catch(function(){});
  },220);
}

function gsRender(d,val){
  var drop=document.getElementById('gsDropdown');
  var hl=function(txt){
    if(!txt) return '';
    var re=new RegExp('('+val.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');
    return String(txt).replace(re,'<strong style="color:var(--primary)">$1</strong>');
  };
  var html='';

  // MASCOTAS
  if(d.mascotas && d.mascotas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px">🐾 Mascotas</div>';
    d.mascotas.forEach(function(m){
      var foto=m.foto_url
        ? '<img src="'+m.foto_url+'" style="width:34px;height:34px;border-radius:8px;object-fit:cover;border:1px solid var(--border);flex-shrink:0">'
        : '<div style="width:34px;height:34px;border-radius:8px;background:var(--primary-l);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">'+(_gsEi[m.especie]||'🐾')+'</div>';
      html+='<div class="gs-item" onclick="gsGoMascota('+m.id+')">'+foto
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(m.nombre)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(m.raza||'')+(m.raza?' · ':'')+hl(m.dueno)+'</div>'
        +'</div>'
        +'<div style="display:flex;gap:4px;flex-shrink:0">'
        +'<span onclick="event.stopPropagation();window.location.href=\''+_gsBase+'/index.php?p=historial&mascota_id='+m.id+'\'" style="padding:3px 8px;background:var(--primary-l);color:var(--primary-d);border-radius:6px;font-size:10px;font-weight:700;cursor:pointer">Historia</span>'
        +'</div>'
        +'</div>';
    });
  }

  // CLIENTES
  if(d.clientes && d.clientes.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">👤 Clientes</div>';
    d.clientes.forEach(function(c){
      html+='<div class="gs-item" onclick="gsGoCliente('+c.id+')">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:var(--accent-l);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--accent-d);flex-shrink:0">'+(c.nombre||'?').charAt(0).toUpperCase()+'</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(c.nombre)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(c.telefono||'')+(c.dni?' · DNI '+c.dni:'')+'</div>'
        +'</div>'
        +'<span style="padding:3px 8px;background:var(--accent-l);color:var(--accent-d);border-radius:6px;font-size:10px;font-weight:700">Ver</span>'
        +'</div>';
    });
  }

  // FACTURAS
  if(d.facturas && d.facturas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">🧾 Facturas</div>';
    d.facturas.forEach(function(f){
      var num=f.serie+'-'+String(f.numero).padStart(5,'0');
      html+='<div class="gs-item" onclick="window.location.href=\''+_gsBase+'/index.php?p=facturacion&action=ver&id='+f.id+'\'">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">🧾</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(num)+'</div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(f.cliente)+' &nbsp;·&nbsp; S/. '+parseFloat(f.total).toFixed(2)+'</div>'
        +'</div>'
        +'<span style="padding:3px 8px;background:#d1fae5;color:#065f46;border-radius:6px;font-size:10px;font-weight:700">Ver</span>'
        +'</div>';
    });
  }

  // CITAS
  if(d.citas && d.citas.length){
    html+='<div style="padding:6px 12px 3px;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;border-top:1px solid var(--border);margin-top:4px">📅 Citas</div>';
    d.citas.forEach(function(c){
      var fecha=c.fecha?c.fecha.split('-').reverse().join('/'):'';
      html+='<div class="gs-item" onclick="window.location.href=\''+_gsBase+'/index.php?p=citas&id='+c.id+'\'">'
        +'<div style="width:34px;height:34px;border-radius:8px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">📅</div>'
        +'<div style="flex:1;min-width:0">'
        +'<div style="font-size:13px;font-weight:600;color:var(--text)">'+hl(c.mascota)+' &nbsp;<span style="font-weight:400;color:var(--text3)">'+fecha+(c.hora?' '+c.hora.substring(0,5):'')+'</span></div>'
        +'<div style="font-size:11px;color:var(--text3)">'+hl(c.cliente)+' &nbsp;·&nbsp; '+ucFirst(c.estado)+'</div>'
        +'</div>'
        +'</div>';
    });
  }

  if(!d.mascotas.length && !d.clientes.length && !(d.facturas&&d.facturas.length) && !(d.citas&&d.citas.length)){
    html='<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">Sin resultados para <strong>'+val+'</strong></div>';
  }

  html+='<div class="gs-item" onclick="gsVerTodos()" style="border-top:1px solid var(--border);margin-top:4px;background:var(--bg3)">'
      +'<div style="font-size:16px;flex-shrink:0">🔍</div>'
      +'<div style="font-size:12px;font-weight:600;color:var(--primary)">Ver todos los resultados para <strong>'+val+'</strong></div>'
      +'</div>';

  drop.innerHTML=html;
  drop.style.display='block';
  _gsFocus=-1;

  drop.querySelectorAll('.gs-item').forEach(function(el){
    el.style.cssText+='display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:pointer;transition:background .1s;border-bottom:1px solid var(--border)';
    el.addEventListener('mouseenter',function(){el.style.background='var(--bg3)';});
    el.addEventListener('mouseleave',function(){el.style.background='';});
  });
}

function ucFirst(s){ return s?s.charAt(0).toUpperCase()+s.slice(1):s; }

function gsKeyDown(e){
  var drop=document.getElementById('gsDropdown');
  var items=drop.querySelectorAll('.gs-item');
  if(!drop.style.display||drop.style.display==='none'){if(e.key==='Enter')gsVerTodos();return;}
  if(e.key==='ArrowDown'){e.preventDefault();_gsFocus=Math.min(_gsFocus+1,items.length-1);items.forEach(function(it,i){it.style.background=i===_gsFocus?'var(--primary-l)':'';});}
  else if(e.key==='ArrowUp'){e.preventDefault();_gsFocus=Math.max(_gsFocus-1,0);items.forEach(function(it,i){it.style.background=i===_gsFocus?'var(--primary-l)':'';});}
  else if(e.key==='Enter'){e.preventDefault();if(_gsFocus>=0&&items[_gsFocus])items[_gsFocus].click();else gsVerTodos();}
  else if(e.key==='Escape'){drop.style.display='none';}
}
function gsGoMascota(id){document.getElementById('gsDropdown').style.display='none';window.location.href=_gsBase+'/index.php?p=historial&mascota_id='+id;}
function gsGoCliente(id){document.getElementById('gsDropdown').style.display='none';window.location.href=_gsBase+'/index.php?p=clientes&action=editar&id='+id;}
function gsVerTodos(){var q=document.getElementById('globalSearchInput');if(q&&q.value.trim())window.location.href=_gsBase+'/index.php?p=buscar&q='+encodeURIComponent(q.value.trim());}
document.addEventListener('click',function(e){
  var w=document.getElementById('globalSearchWrap');
  if(w&&!w.contains(e.target)){var d=document.getElementById('gsDropdown');if(d)d.style.display='none';}
});
</script>
</body>
</html>

// Searchable dropdown ringan tanpa dependensi.
// Meng-enhance setiap <select data-searchable> menjadi input pencarian + daftar terfilter.
// <select> asli tetap dipakai sebagai sumber nilai (progressive enhancement).
(function () {
  function enhance(select) {
    if (select.dataset.enhanced) return;
    select.dataset.enhanced = '1';

    // Baca opsi secara LIVE setiap render agar filter eksternal (mis. by
    // kecamatan/desa) langsung tercermin. Filter eksternal menandai opsi yang
    // disembunyikan via atribut data-filtered="1" (lebih andal lintas-browser
    // daripada properti .hidden pada <option> di dalam <select>).
    function liveOptions() {
      return Array.prototype.slice.call(select.options).filter(function (o) {
        return o.getAttribute('data-filtered') !== '1' && !o.disabled;
      });
    }
    var placeholder = select.dataset.placeholder || '— pilih —';

    // Bungkus
    var wrap = document.createElement('div');
    wrap.className = 'ss';
    select.parentNode.insertBefore(wrap, select);
    select.style.display = 'none';
    wrap.appendChild(select);

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'ss-input';
    input.placeholder = placeholder;
    input.autocomplete = 'off';
    input.required = select.required;
    // Nilai awal mengikuti pilihan select (mis. setelah reload)
    if (select.value) input.value = select.options[select.selectedIndex].dataset.label || select.value;
    wrap.appendChild(input);

    var menu = document.createElement('div');
    menu.className = 'ss-menu';
    menu.hidden = true;
    wrap.appendChild(menu);

    var active = -1;

    function items() {
      return Array.prototype.slice.call(menu.querySelectorAll('.ss-item'));
    }

    function render(filter) {
      menu.innerHTML = '';
      var q = (filter || '').toLowerCase().trim();
      var count = 0;
      liveOptions().forEach(function (opt) {
        if (!opt.value) return; // lewati placeholder
        var label = opt.dataset.label || opt.textContent.trim();
        if (q && label.toLowerCase().indexOf(q) === -1) return;
        var item = document.createElement('div');
        item.className = 'ss-item';
        item.textContent = label;
        item.dataset.value = opt.value;
        item.addEventListener('mousedown', function (e) {
          e.preventDefault();
          choose(opt.value, label);
        });
        menu.appendChild(item);
        count++;
      });
      if (count === 0) {
        var empty = document.createElement('div');
        empty.className = 'ss-empty';
        empty.textContent = 'Tidak ada hasil';
        menu.appendChild(empty);
      }
      active = -1;
    }

    function open() {
      render(input.value === lastChosenLabel ? '' : input.value);
      menu.hidden = false;
    }
    function close() {
      menu.hidden = true;
    }

    var lastChosenLabel = input.value || '';

    function choose(value, label) {
      select.value = value;
      input.value = label;
      lastChosenLabel = label;
      input.classList.remove('ss-invalid');
      close();
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setActive(i) {
      var list = items();
      if (!list.length) return;
      active = (i + list.length) % list.length;
      list.forEach(function (el, idx) {
        el.classList.toggle('ss-active', idx === active);
      });
      list[active].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('focus', open);
    input.addEventListener('input', function () {
      select.value = ''; // batalkan pilihan saat mengetik ulang
      open();
    });
    input.addEventListener('keydown', function (e) {
      if (menu.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { open(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
      else if (e.key === 'Enter') {
        var list = items();
        if (!menu.hidden && active >= 0 && list[active]) {
          e.preventDefault();
          choose(list[active].dataset.value, list[active].textContent);
        }
      } else if (e.key === 'Escape') { close(); }
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        // Jika teks tidak cocok dengan pilihan valid, tandai
        if (!select.value && input.value.trim() !== '') {
          input.classList.add('ss-invalid');
        }
        close();
      }, 120);
    });

    // API ringan untuk pemanggil eksternal: panggil setelah mengubah visibilitas
    // <option>. Bila pilihan saat ini tak lagi tersedia, kosongkan input.
    select.ssRefresh = function () {
      var cur = select.value;
      if (cur) {
        var stillThere = liveOptions().some(function (o) { return o.value === cur; });
        if (!stillThere) {
          select.value = '';
          input.value = '';
          lastChosenLabel = '';
          input.classList.remove('ss-invalid');
        }
      }
      if (!menu.hidden) render(input.value === lastChosenLabel ? '' : input.value);
    };

    render('');
  }

  function init() {
    document.querySelectorAll('select[data-searchable]').forEach(enhance);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

(function () {
    'use strict';

    var racine = document.documentElement;
    var corps = document.body;
    var reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var btnTheme = document.getElementById('btn-theme');
    if (btnTheme) {
        btnTheme.addEventListener('click', function () {
            var t = racine.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            racine.setAttribute('data-theme', t);
            try { localStorage.setItem('dren_theme', t); } catch (e) {}
        });
    }

    
    var btnMenu = document.getElementById('btn-menu');
    var voile = document.getElementById('voile');
    function menu(ouvert) {
        corps.classList.toggle('menu-ouvert', ouvert);
        if (btnMenu) btnMenu.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    }
    if (btnMenu) btnMenu.addEventListener('click', function () { menu(!corps.classList.contains('menu-ouvert')); });
    if (voile) voile.addEventListener('click', function () { menu(false); });

    var boutons = document.querySelectorAll('[data-dd]');
    function fermerMenus(sauf) {
        boutons.forEach(function (b) {
            if (b === sauf) return;
            b.setAttribute('aria-expanded', 'false');
            var m = b.parentNode.querySelector('.dd-menu');
            if (m) m.hidden = true;
        });
    }
    boutons.forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            fermerMenus(b);
            var m = b.parentNode.querySelector('.dd-menu');
            var ouvrir = m.hidden;
            m.hidden = !ouvrir;
            b.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function () { fermerMenus(null); });

    document.addEventListener('keydown', function (e) {
        var cible = e.target.tagName;
        if (e.key === '/' && cible !== 'INPUT' && cible !== 'TEXTAREA' && cible !== 'SELECT') {
            var r = document.getElementById('recherche-globale');
            if (r) { e.preventDefault(); r.focus(); }
        }
        if (e.key === 'Escape') { fermerMenus(null); menu(false); }
    });

    document.querySelectorAll('.stat b, .kpi b').forEach(function (el) {
        var fin = parseInt(el.textContent, 10);
        if (isNaN(fin) || reduit || fin === 0) return;
        var debut = null, duree = 700;
        function pas(t) {
            if (debut === null) debut = t;
            var p = Math.min((t - debut) / duree, 1);
            el.textContent = Math.round(fin * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(pas);
        }
        el.textContent = '0';
        requestAnimationFrame(pas);
    });

    document.querySelectorAll('.succes').forEach(function (el) {
        function cacher() {
            el.classList.add('fermer');
            setTimeout(function () { el.hidden = true; }, 500);
        }
        el.title = 'Cliquer pour fermer';
        el.addEventListener('click', cacher);
        setTimeout(cacher, 6000);
    });


    function valeur(td) {
        var t = td.textContent.trim();
        var d = t.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (d) return parseInt(d[3] + d[2] + d[1], 10);
        if (/^[\d\s.,]+$/.test(t) && /\d/.test(t)) return parseFloat(t.replace(/\s/g, '').replace(',', '.'));
        return t.toLowerCase();
    }

    document.querySelectorAll('main table').forEach(function (table) {
        if (table.hasAttribute('data-sans-outils')) return;

        var lignes = Array.prototype.slice.call(table.rows).filter(function (r) {
            return r.querySelector('td') && !r.querySelector('td[colspan]');
        });
        if (!lignes.length) return;
        var entete = table.rows[0].querySelector('th') ? table.rows[0] : null;
        var parent = lignes[0].parentNode;

        if (entete && lignes.length > 1) {
            Array.prototype.forEach.call(entete.cells, function (th, i) {
                if (!th.textContent.trim()) return;
                th.classList.add('tri');
                th.tabIndex = 0;
                function trier() {
                    var asc = th.getAttribute('aria-sort') !== 'ascending';
                    Array.prototype.forEach.call(entete.cells, function (x) { x.removeAttribute('aria-sort'); });
                    th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
                    lignes.sort(function (a, b) {
                        var x = valeur(a.cells[i]), y = valeur(b.cells[i]);
                        if (x < y) return asc ? -1 : 1;
                        if (x > y) return asc ? 1 : -1;
                        return 0;
                    }).forEach(function (r) { parent.appendChild(r); });
                }
                th.addEventListener('click', trier);
                th.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); trier(); }
                });
            });
        }

        if (lignes.length >= 6) {
            var bloc = document.createElement('div');
            bloc.className = 'filtre';
            bloc.innerHTML = '<input type="search" placeholder="Filtrer ce tableau…" aria-label="Filtrer ce tableau">'
                           + '<span></span>';
            table.parentNode.insertBefore(bloc, table);
            var champ = bloc.querySelector('input'), info = bloc.querySelector('span');
            champ.addEventListener('input', function () {
                var q = champ.value.trim().toLowerCase(), n = 0;
                lignes.forEach(function (r) {
                    var ok = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
                    r.hidden = !ok;
                    if (ok) n++;
                });
                info.textContent = q ? n + ' résultat(s)' : '';
            });
        }
    });

    document.querySelectorAll('form[method="post"]').forEach(function (f) {
        if (f.hasAttribute('data-sans-garde')) return;
        f.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            setTimeout(function () {
                f.querySelectorAll('button[type="submit"]').forEach(function (b) {
                    b.disabled = true;
                    b.textContent = 'Enregistrement…';
                });
            }, 0);
        });
    });
})();

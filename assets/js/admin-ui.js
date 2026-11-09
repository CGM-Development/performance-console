(function () {
    'use strict';
    var root = document.querySelector('.cgm-pfc-root');
    if (!root) { return; }

    var legacyView = (window.location.hash || '').replace(/^#/, '');
    var allowedViews = ['overview', 'findings', 'database', 'profiling', 'monitoring', 'system', 'cache'];
    var activeView = root.getAttribute('data-active-view') || 'overview';
    if (allowedViews.indexOf(legacyView) !== -1 && legacyView !== activeView) {
        var legacyUrl = new URL(window.location.href);
        legacyUrl.searchParams.set('view', legacyView);
        window.location.replace(legacyUrl.toString());
        return;
    }

    var sectionNav = root.querySelector('.pfc-suite-nav');
    var navWrap = root.querySelector('.pfc-suite-nav-wrap');
    function updateNavOverflow() {
        if (!sectionNav || !navWrap) { return; }
        var overflowing = sectionNav.scrollWidth > sectionNav.clientWidth + 2;
        var atEnd = sectionNav.scrollLeft + sectionNav.clientWidth >= sectionNav.scrollWidth - 4;
        navWrap.classList.toggle('is-overflowing', overflowing);
        navWrap.classList.toggle('is-at-end', atEnd);
    }
    if (sectionNav) {
        var activeTab = sectionNav.querySelector('[aria-current="page"]');
        function centerActiveTab() {
            if (activeTab) {
                sectionNav.scrollLeft = Math.max(0, activeTab.offsetLeft - (sectionNav.clientWidth - activeTab.offsetWidth) / 2);
            }
        }
        centerActiveTab();
        sectionNav.addEventListener('scroll', updateNavOverflow, { passive: true });
        window.addEventListener('resize', function () { centerActiveTab(); updateNavOverflow(); });
        updateNavOverflow();
    }

    function openAnchorTarget() {
        if (!window.location.hash) { return; }
        var target = root.querySelector(window.location.hash);
        if (!target) { return; }
        var disclosure = target.tagName.toLowerCase() === 'details' ? target : target.closest('details');
        while (disclosure) {
            disclosure.open = true;
            disclosure = disclosure.parentElement ? disclosure.parentElement.closest('details') : null;
        }
    }
    openAnchorTarget();
    window.addEventListener('hashchange', openAnchorTarget);

    Array.prototype.forEach.call(root.querySelectorAll('[data-pfc-submit-change]'), function (control) {
        control.addEventListener('change', function () { if (control.form) { control.form.submit(); } });
    });

    var incidentCards = Array.prototype.slice.call(root.querySelectorAll('.pfc-incident'));
    var severityFilter = root.querySelector('[data-pfc-filter="severity"]');
    var searchFilter = root.querySelector('[data-pfc-filter="search"]');
    var resultCount = root.querySelector('[data-pfc-result-count]');
    var pagination = root.querySelector('[data-pfc-pagination]');
    var pageStatus = root.querySelector('[data-pfc-page-status]');
    var noResults = root.querySelector('[data-pfc-no-results]');
    var previousPage = root.querySelector('[data-pfc-page="previous"]');
    var nextPage = root.querySelector('[data-pfc-page="next"]');
    var searchTimer = null;
    var currentPage = 1;
    var pageSize = 4;
    var matchingCards = incidentCards.slice();

    function renderIncidents() {
        var total = matchingCards.length;
        var pages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = Math.min(currentPage, pages);
        var start = (currentPage - 1) * pageSize;
        var end = Math.min(start + pageSize, total);
        incidentCards.forEach(function (card) { card.hidden = true; });
        matchingCards.slice(start, end).forEach(function (card) { card.hidden = false; });
        if (resultCount) { resultCount.textContent = String(total); }
        if (noResults) { noResults.hidden = total !== 0; }
        if (pagination) { pagination.hidden = total <= pageSize; }
        if (pageStatus) {
            pageStatus.textContent = total ? 'Showing ' + (start + 1) + '–' + end + ' of ' + total + ' · Page ' + currentPage + ' of ' + pages : 'No matching incidents';
        }
        if (previousPage) { previousPage.disabled = currentPage <= 1; }
        if (nextPage) { nextPage.disabled = currentPage >= pages; }
    }
    function filterIncidents() {
        var severity = severityFilter ? severityFilter.value : '';
        var search = searchFilter ? searchFilter.value.trim().toLowerCase() : '';
        matchingCards = incidentCards.filter(function (card) {
            var matchesSeverity = !severity || card.getAttribute('data-severity') === severity;
            var matchesSearch = !search || (card.getAttribute('data-search') || '').indexOf(search) !== -1;
            return matchesSeverity && matchesSearch;
        });
        currentPage = 1;
        renderIncidents();
    }
    if (severityFilter) { severityFilter.addEventListener('change', filterIncidents); }
    if (searchFilter) { searchFilter.addEventListener('input', function () { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(filterIncidents, 120); }); }
    if (previousPage) { previousPage.addEventListener('click', function () { currentPage--; renderIncidents(); pagination.scrollIntoView({ block: 'nearest' }); }); }
    if (nextPage) { nextPage.addEventListener('click', function () { currentPage++; renderIncidents(); pagination.scrollIntoView({ block: 'nearest' }); }); }
    if (incidentCards.length) { renderIncidents(); }

    var overflowTables = [];
    Array.prototype.forEach.call(root.querySelectorAll('table.widefat:not(.pfc-responsive-table)'), function (table) {
        if (table.closest('.pfc-scroll-x')) { return; }
        var wrapper = document.createElement('div');
        wrapper.className = 'pfc-table-scroll';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
        var hint = document.createElement('span');
        hint.className = 'pfc-table-overflow';
        hint.setAttribute('aria-hidden', 'true');
        hint.innerHTML = 'Scroll for more columns <span class="dashicons dashicons-arrow-right-alt2"></span>';
        wrapper.appendChild(hint);
        overflowTables.push({ table: table, wrapper: wrapper });
    });
    function updateTableOverflow() {
        overflowTables.forEach(function (item) {
            var overflowing = item.table.scrollWidth > item.table.clientWidth + 2;
            item.wrapper.classList.toggle('is-overflowing', overflowing);
            item.table.tabIndex = overflowing ? 0 : -1;
        });
    }
    if (overflowTables.length) {
        window.addEventListener('resize', updateTableOverflow);
        updateTableOverflow();
    }

    Array.prototype.forEach.call(root.querySelectorAll('form[method="post"]'), function (form) {
        form.addEventListener('submit', function (event) {
            var button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
            if (!button || button.disabled) { return; }
            form.setAttribute('aria-busy', 'true');
            window.setTimeout(function () {
                button.classList.add('is-busy');
                button.setAttribute('aria-busy', 'true');
                button.disabled = true;
            }, 0);
        });
    });
}());

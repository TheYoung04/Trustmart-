</div><!-- end .shell -->

<!-- Toast notification -->
<div class="toast info" id="toast"></div>

<script>
// Shared utility functions available on every dashboard page

function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast show ' + type;
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3200);
}

function badgeClass(status) {
    return {
        Menunggu:   'badge-gold',
        Disahkan:   'badge-blue',
        Selesai:    'badge-green',
        Dibatalkan: 'badge-red',
        Aktif:      'badge-green',
        'Tidak Aktif': 'badge-gray',
    }[status] || 'badge-gray';
}

function trustColor(score) {
    score = parseFloat(score);
    return score >= 85 ? 'var(--green)' : score >= 70 ? 'var(--gold)' : 'var(--red)';
}

function esc(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDate(str) {
    if (!str) return '—';
    return new Date(str).toLocaleDateString('ms-MY', {
        day: '2-digit', month: 'short', year: 'numeric'
    });
}

// Close modal on Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bg').forEach(m => m.style.display = 'none');
    }
});

// Check for ?msg= in URL and show toast
const _urlMsg = new URLSearchParams(location.search).get('msg');
if (_urlMsg === 'logout')  showToast('Anda telah log keluar.', 'info');
if (_urlMsg === 'session') showToast('Sesi tamat. Sila log masuk semula.', 'error');
</script>
</body>
</html>
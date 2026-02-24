<!-- Format Toolbar: B/I buttons for textareas with data-format-toolbar -->
<style>
.format-toolbar {
    display: flex;
    gap: var(--space-2xs);
    padding: var(--space-2xs) var(--space-xs);
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border);
    border-bottom: none;
    border-radius: var(--radius-sm) var(--radius-sm) 0 0;
}
.format-toolbar + textarea,
.format-toolbar + .admin-form-input,
.format-toolbar + .input,
.format-toolbar + .global-text-textarea,
.format-toolbar + .facility-textarea {
    border-top-left-radius: 0 !important;
    border-top-right-radius: 0 !important;
}
.format-toolbar-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg-card);
    color: var(--color-text-secondary);
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    transition: all 0.15s ease;
}
.format-toolbar-btn:hover {
    background: var(--color-accent-light);
    color: var(--color-accent-text);
    border-color: var(--color-accent);
}
.format-toolbar-btn--italic {
    font-style: italic;
    font-weight: 400;
    font-family: Georgia, serif;
}
.format-toolbar-hint {
    display: flex;
    align-items: center;
    margin-left: auto;
    font-size: 11px;
    color: var(--color-text-muted);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-format-toolbar]').forEach(function(textarea) {
        // Skip if toolbar already added
        if (textarea.dataset.toolbarInit) return;
        textarea.dataset.toolbarInit = '1';

        var toolbar = document.createElement('div');
        toolbar.className = 'format-toolbar';

        // Bold button
        var boldBtn = document.createElement('button');
        boldBtn.type = 'button';
        boldBtn.className = 'format-toolbar-btn';
        boldBtn.textContent = 'B';
        boldBtn.title = 'Fetstil (Ctrl+B)';
        boldBtn.addEventListener('click', function() {
            wrapSelection(textarea, '**', '**');
        });

        // Italic button
        var italicBtn = document.createElement('button');
        italicBtn.type = 'button';
        italicBtn.className = 'format-toolbar-btn format-toolbar-btn--italic';
        italicBtn.textContent = 'I';
        italicBtn.title = 'Kursiv (Ctrl+I)';
        italicBtn.addEventListener('click', function() {
            wrapSelection(textarea, '*', '*');
        });

        // Hint
        var hint = document.createElement('span');
        hint.className = 'format-toolbar-hint';
        hint.textContent = '**fet** *kursiv*';

        toolbar.appendChild(boldBtn);
        toolbar.appendChild(italicBtn);
        toolbar.appendChild(hint);

        textarea.parentNode.insertBefore(toolbar, textarea);

        // Keyboard shortcuts
        textarea.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                wrapSelection(textarea, '**', '**');
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'i') {
                e.preventDefault();
                wrapSelection(textarea, '*', '*');
            }
        });
    });

    function wrapSelection(textarea, before, after) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var text = textarea.value;
        var selected = text.substring(start, end);

        // If text is already wrapped, unwrap it
        var beforeText = text.substring(Math.max(0, start - before.length), start);
        var afterText = text.substring(end, end + after.length);
        if (beforeText === before && afterText === after) {
            textarea.value = text.substring(0, start - before.length) + selected + text.substring(end + after.length);
            textarea.selectionStart = start - before.length;
            textarea.selectionEnd = end - before.length;
            textarea.focus();
            return;
        }

        // Wrap the selection
        var replacement = before + selected + after;
        textarea.value = text.substring(0, start) + replacement + text.substring(end);

        // If no selection, place cursor between markers
        if (start === end) {
            textarea.selectionStart = textarea.selectionEnd = start + before.length;
        } else {
            textarea.selectionStart = start + before.length;
            textarea.selectionEnd = end + before.length;
        }
        textarea.focus();
    }
});
</script>

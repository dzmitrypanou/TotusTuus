<?php
declare(strict_types=1);

/**
 * Разметка WYSIWYG як «Тэкст малітвы» у admin/index.php.
 *
 * @var string $panel_rich_editor_label
 * @var string $panel_rich_editor_field_id id textarea + data-target-id
 * @var string $panel_rich_editor_field_name name у POST
 * @var string $panel_rich_editor_editor_id id contenteditable
 * @var string $panel_rich_editor_data_attr поўны атрыбут data-initial-html="..." або ''
 * @var string $panel_rich_editor_aria_label калі подпіс пусты — для aria-label на рэдактары
 */
$panel_rich_editor_label = $panel_rich_editor_label ?? '';
$panel_rich_editor_aria_label = trim((string)($panel_rich_editor_aria_label ?? ''));
$panel_rich_editor_field_id = $panel_rich_editor_field_id ?? 'rich_html';
$panel_rich_editor_field_name = $panel_rich_editor_field_name ?? $panel_rich_editor_field_id;
$panel_rich_editor_editor_id = $panel_rich_editor_editor_id ?? ($panel_rich_editor_field_id . '_editor');
$panel_rich_editor_data_attr = $panel_rich_editor_data_attr ?? '';

$panel_rich_editor_label_trim = trim((string)$panel_rich_editor_label);
$panel_rich_editor_aria_for_editor = $panel_rich_editor_label_trim !== ''
    ? $panel_rich_editor_label_trim
    : ($panel_rich_editor_aria_label !== '' ? $panel_rich_editor_aria_label : 'Тэкст');
?>
<?php if ($panel_rich_editor_label_trim !== ''): ?>
<label for="<?= htmlspecialchars($panel_rich_editor_editor_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($panel_rich_editor_label_trim, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
<?php endif; ?>
<div class="rich-editor-wrap">
  <div class="rich-toolbar">
    <div class="rich-toolbar-group">
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="underline" title="Падкрэслены" aria-label="Падкрэслены"><u>U</u></button>
    </div>
    <div class="rich-toolbar-group">
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertUnorderedList" title="Маркіраваны спіс" aria-label="Маркіраваны спіс">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/></svg>
      </button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="insertOrderedList" title="Нумараваны спіс" aria-label="Нумараваны спіс">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-6v2h14V5H7zm0 14h14v-2H7v2zm0-6h14v-2H7v2z"/></svg>
      </button>
    </div>
    <div class="rich-toolbar-group">
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Выраўнованне ўлева" aria-label="Выраўнованне ўлева">L</button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Выраўнованне па цэнтры" aria-label="Выраўнованне па цэнтры">C</button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Выраўнованне ўправа" aria-label="Выраўнованне ўправа">R</button>
      <button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Выраўнованне па шырыні" aria-label="Выраўнованне па шырыні">J</button>
    </div>
    <div class="rich-toolbar-group">
      <button type="button" class="rich-btn" data-cmd="formatBlock" data-value="h3" title="Загаловак">Загаловак</button>
      <button type="button" class="rich-btn" data-cmd="removeFormat" title="Ачысціць фарматаванне">Ачысціць</button>
    </div>
    <div class="rich-toolbar-group rich-toolbar-group--row-break" title="Колер вылучанага тэксту">
      <span class="rich-toolbar-label">Колер</span>
      <div class="rich-color-picker-wrap">
        <button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>
        <div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">
          <button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;" title="Чорны"></button>
          <button type="button" class="rich-color-swatch" data-color="#1f2937" style="background:#1f2937;" title="Графіт"></button>
          <button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;" title="Цёмна-шэры"></button>
          <button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;" title="Шэры"></button>
          <button type="button" class="rich-color-swatch" data-color="#9ca3af" style="background:#9ca3af;" title="Светла-шэры"></button>
          <button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;" title="Белы"></button>
          <button type="button" class="rich-color-swatch" data-color="#7f1d1d" style="background:#7f1d1d;" title="Бардовы"></button>
          <button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;" title="Цёмна-чырвоны"></button>
          <button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;" title="Чырвоны"></button>
          <button type="button" class="rich-color-swatch" data-color="#f87171" style="background:#f87171;" title="Светла-чырвоны"></button>
          <button type="button" class="rich-color-swatch" data-color="#7c2d12" style="background:#7c2d12;" title="Карычневы"></button>
          <button type="button" class="rich-color-swatch" data-color="#c2410c" style="background:#c2410c;" title="Цёмна-аранжавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;" title="Аранжавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#fb923c" style="background:#fb923c;" title="Светла-аранжавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#854d0e" style="background:#854d0e;" title="Гарчычны"></button>
          <button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;" title="Жоўты"></button>
          <button type="button" class="rich-color-swatch" data-color="#fde047" style="background:#fde047;" title="Светла-жоўты"></button>
          <button type="button" class="rich-color-swatch" data-color="#3f6212" style="background:#3f6212;" title="Аліўкавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#15803d" style="background:#15803d;" title="Цёмна-зялёны"></button>
          <button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Зялёны"></button>
          <button type="button" class="rich-color-swatch" data-color="#4ade80" style="background:#4ade80;" title="Светла-зялёны"></button>
          <button type="button" class="rich-color-swatch" data-color="#0f766e" style="background:#0f766e;" title="Цёмна-бірузовы"></button>
          <button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;" title="Бірузовы"></button>
          <button type="button" class="rich-color-swatch" data-color="#2dd4bf" style="background:#2dd4bf;" title="Светла-бірузовы"></button>
          <button type="button" class="rich-color-swatch" data-color="#1e3a8a" style="background:#1e3a8a;" title="Цёмна-сіні"></button>
          <button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;" title="Сіні"></button>
          <button type="button" class="rich-color-swatch" data-color="#60a5fa" style="background:#60a5fa;" title="Светла-сіні"></button>
          <button type="button" class="rich-color-swatch" data-color="#312e81" style="background:#312e81;" title="Індыга"></button>
          <button type="button" class="rich-color-swatch" data-color="#4f46e5" style="background:#4f46e5;" title="Светла-індыга"></button>
          <button type="button" class="rich-color-swatch" data-color="#581c87" style="background:#581c87;" title="Цёмна-фіялетавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;" title="Фіялетавы"></button>
          <button type="button" class="rich-color-swatch" data-color="#d946ef" style="background:#d946ef;" title="Пурпурны"></button>
        </div>
      </div>
    </div>
  </div>
  <div id="<?= htmlspecialchars($panel_rich_editor_editor_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="rich-editor js-rich-editor" data-target-id="<?= htmlspecialchars($panel_rich_editor_field_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?php echo $panel_rich_editor_data_attr; ?> contenteditable="true" role="textbox" aria-multiline="true" aria-label="<?= htmlspecialchars($panel_rich_editor_aria_for_editor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
</div>
<textarea id="<?= htmlspecialchars($panel_rich_editor_field_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="rich-editor-hidden" name="<?= htmlspecialchars($panel_rich_editor_field_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></textarea>

<?php
/**
 * Plugin Name: WP Summiteo
 * Description: Connecteur métier sécurisé pour dupliquer et adapter les pages Elementor des comptoirs Maison Française de l'Or.
 * Version: 58.0.0
 * Author: Summiteo
 */

if (!defined('ABSPATH')) { exit; }

class WP_Summiteo {
    const VERSION = '58.0.0';
    const OPTION = 'wp_summiteo_settings';
    const LEGACY_OPTION = 'goldinfo_ai_connector_settings';
    const NS = 'wp-summiteo/v1';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_wp_summiteo_search_pages', [$this, 'ajax_search_pages']);
        add_action('wp_ajax_wp_summiteo_admin_clone_page', [$this, 'ajax_admin_clone_page']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filter_update_plugins']);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
    }

    public static function defaults() {
        return [
            'source_city' => 'La Rochelle',
            'target_city' => 'Bordeaux',
            'target_department' => 'Gironde',
            'selected_source_id' => '',
            'selected_ai_page_id' => '',
            'replacements' => "La Rochelle|Bordeaux\nRochelais|Bordelais\nCharente-Maritime|Gironde\ncomptoir-la-rochelle|comptoir-bordeaux\nachat-d-or-la-rochelle|achat-d-or-bordeaux\nvente-or-la-rochelle|vente-or-bordeaux\nachat-argent-la-rochelle|achat-argent-bordeaux\nvente-dargent-la-rochelle|vente-dargent-bordeaux\nvente-de-bijoux-anciens-a-la-rochelle|vente-de-bijoux-anciens-a-bordeaux",
            'seo_title_tpl' => '{TITLE}',
            'seo_desc_tpl' => '',
            'editorial_brief' => '',
            'openai_enabled' => '0',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
            'unsplash_access_key' => '',
            'update_manifest_url' => 'https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json',
            'ai_block_limit' => '3',
        ];
    }

    public static function settings() {
        $settings = get_option(self::OPTION, null);
        if (!is_array($settings)) {
            $legacy = get_option(self::LEGACY_OPTION, []);
            if (is_array($legacy) && !empty($legacy)) {
                update_option(self::OPTION, $legacy, false);
                $settings = $legacy;
            } else {
                $settings = [];
            }
        }
        $settings = wp_parse_args($settings, self::defaults());
        if (empty($settings['update_manifest_url'])) {
            $settings['update_manifest_url'] = self::defaults()['update_manifest_url'];
            update_option(self::OPTION, $settings, false);
        }
        return $settings;
    }

    public function admin_menu() {
        add_menu_page('WP Summiteo', 'WP Summiteo', 'manage_options', 'wp-summiteo', [$this, 'admin_page'], 'dashicons-superhero-alt', 58);
        add_management_page('WP Summiteo', 'WP Summiteo', 'manage_options', 'wp-summiteo', [$this, 'admin_page']);
        remove_submenu_page('tools.php', 'wp-summiteo');
    }

    public function register_settings() {
        register_setting('wp_summiteo_group', self::OPTION, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $defaults = self::defaults();
        $out = [];
        foreach ($defaults as $key => $val) {
            if ($key === 'openai_enabled') {
                $out[$key] = !empty($input[$key]) ? '1' : '0';
            } elseif ($key === 'openai_api_key' || $key === 'unsplash_access_key') {
                $out[$key] = isset($input[$key]) ? trim(sanitize_text_field($input[$key])) : '';
            } elseif ($key === 'update_manifest_url') {
                $out[$key] = isset($input[$key]) ? esc_url_raw(trim((string)$input[$key])) : '';
            } elseif ($key === 'ai_block_limit') {
                $out[$key] = isset($input[$key]) ? max(1, min(30, absint($input[$key]))) : 3;
            } elseif ($key === 'replacements' || $key === 'editorial_brief') {
                $out[$key] = isset($input[$key]) ? wp_kses_post($input[$key]) : $val;
            } else {
                $out[$key] = isset($input[$key]) ? sanitize_text_field($input[$key]) : $val;
            }
        }
        return $out;
    }

    public function admin_assets($hook) {
        if (!in_array($hook, ['toplevel_page_wp-summiteo', 'tools_page_wp-summiteo'], true)) { return; }
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', $this->admin_js());
        wp_add_inline_style('wp-admin', $this->admin_css());
    }

    private function admin_css() {
        return '.summiteo-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0;max-width:1100px}.summiteo-tabs{display:flex;gap:6px;margin:18px 0 0;max-width:1100px;border-bottom:1px solid #c3c4c7}.summiteo-tab{appearance:none;background:#f6f7f7;border:1px solid #c3c4c7;border-bottom:0;border-radius:6px 6px 0 0;color:#1d2327;cursor:pointer;font-weight:600;margin:0;padding:10px 14px}.summiteo-tab.is-active{background:#fff;color:#2271b1;box-shadow:inset 0 3px 0 #2271b1}.summiteo-tab-panel{display:none}.summiteo-tab-panel.is-active{display:block}.summiteo-row{display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:center;margin:12px 0}.summiteo-results{margin-top:12px}.summiteo-page{border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;background:#fafafa;display:flex;justify-content:space-between;gap:12px}.summiteo-muted{color:#646970}.summiteo-pill{display:inline-block;padding:2px 7px;border-radius:99px;background:#f0f0f1;margin-left:6px}.summiteo-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.summiteo-log{background:#1d2327;color:#f6f7f7;padding:12px;border-radius:6px;white-space:pre-wrap;max-width:1100px;overflow:auto} textarea.large-text{font-family:monospace}.summiteo-image-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px}.summiteo-image-card{border:1px solid #dcdcde;border-radius:6px;background:#fafafa;padding:10px}.summiteo-image-card img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:4px;background:#f0f0f1}.summiteo-image-card.is-selected{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}.summiteo-image-card strong{display:block;margin:8px 0 4px}.summiteo-image-card .button{margin-top:8px}';
    }

    private function admin_js() {
        $nonce = wp_create_nonce('wp_summiteo_admin');
        $rest_nonce = wp_create_nonce('wp_rest');
        $rest_root = esc_url_raw(rest_url(self::NS));
        $replacement_pairs = wp_json_encode($this->replacement_pairs());
        return <<<JS
jQuery(function($){
  const nonce = '{$nonce}';
  const restNonce = '{$rest_nonce}';
  const restRoot = '{$rest_root}';
  const replacementPairs = {$replacement_pairs};
  function log(msg){ $('#summiteo-log').text(typeof msg === 'string' ? msg : JSON.stringify(msg,null,2)); }
  $('.summiteo-tab').on('click', function(){
    const tab = $(this).data('tab');
    $('.summiteo-tab').removeClass('is-active').attr('aria-selected', 'false');
    $(this).addClass('is-active').attr('aria-selected', 'true');
    $('.summiteo-tab-panel').removeClass('is-active');
    $('#summiteo-tab-' + tab).addClass('is-active');
  });
  function slugify(value){
    return String(value || '')
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'ville';
  }
  function applyConfiguredReplacements(value){
    let out = String(value || '');
    Object.keys(replacementPairs || {}).forEach(function(source){
      out = out.split(source).join(replacementPairs[source]);
    });
    return out;
  }

  function renderPageResults(target, items, mode){
    let html='';
    items.forEach(function(p){
      const safeTitle = $('<div>').text(p.title).html();
      const actionClass = mode === 'ai' ? 'summiteo-use-ai-page' : 'summiteo-use-source';
      const actionText = mode === 'ai' ? 'Utiliser pour la réécriture IA' : 'Utiliser comme source';
      html += '<div class="summiteo-page"><div><strong>'+safeTitle+'</strong><br><span class="summiteo-muted">ID '+p.id+' · /'+p.slug+'/ · '+p.type+' · '+p.status+'</span>'+(p.is_elementor ? '<span class="summiteo-pill">Elementor</span>' : '')+'</div><div class="summiteo-actions"><button type="button" class="button '+actionClass+'" data-id="'+p.id+'" data-title="'+safeTitle+'" data-slug="'+p.slug+'">'+actionText+'</button><a class="button" target="_blank" href="'+p.edit_url+'">Modifier</a></div></div>';
    });
    $(target).html(html || '<p>Aucun résultat.</p>');
  }

  $('#summiteo-search-btn').on('click', function(e){
    e.preventDefault();
    const q = $('#summiteo-source-search').val();
    $('#summiteo-results').html('<p>Recherche...</p>');
    $.post(ajaxurl, {action:'wp_summiteo_search_pages', nonce:nonce, search:q}, function(resp){
      if(!resp.success){ $('#summiteo-results').html('<p>Erreur : '+resp.data+'</p>'); return; }
      renderPageResults('#summiteo-results', resp.data.items, 'clone');
    });
  });

  $(document).on('click','.summiteo-use-source', function(){
    const id=$(this).data('id'), title=$(this).data('title'), slug=$(this).data('slug');
    $('#selected_source_id').val(id);
    $('#selected_source_label').text('Source sélectionnée : ID '+id+' · '+title+' · /'+slug+'/');
    const targetCity = $('#target_city').val() || '';
    let cleanTitle = applyConfiguredReplacements(title);
    let cleanSlug = applyConfiguredReplacements(slug);
    if (cleanTitle === String(title) && targetCity) {
      cleanTitle = String(title).replace(/La Rochelle/g, targetCity).replace(/Rochelais/g, targetCity);
    }
    if (cleanSlug === String(slug) && targetCity) {
      cleanSlug = String(slug).replace(/la-rochelle/g, slugify(targetCity));
    }
    $('#new_title').val(cleanTitle);
    $('#new_slug').val(slugify(cleanSlug));
  });

  $('#summiteo-ai-search-btn').on('click', function(e){
    e.preventDefault();
    const q = $('#summiteo-ai-page-search').val();
    $('#summiteo-ai-results').html('<p>Recherche...</p>');
    $.post(ajaxurl, {action:'wp_summiteo_search_pages', nonce:nonce, search:q}, function(resp){
      if(!resp.success){ $('#summiteo-ai-results').html('<p>Erreur : '+resp.data+'</p>'); return; }
      renderPageResults('#summiteo-ai-results', resp.data.items, 'ai');
    });
  });

  $(document).on('click','.summiteo-use-ai-page', function(){
    const id=$(this).data('id'), title=$(this).data('title'), slug=$(this).data('slug');
    $('#ai_page_id').val(id);
    $('#selected_ai_page_label').text('Contenu IA sélectionné : ID '+id+' · '+title+' · /'+slug+'/');
  });

  $('#summiteo-clone-admin-btn').on('click', function(e){
    e.preventDefault();
    const source_id = $('#selected_source_id').val();
    if(!source_id){ alert('Sélectionne d\'abord une page source.'); return; }
    log('Clonage en cours...');
    $.post(ajaxurl, {
      action:'wp_summiteo_admin_clone_page', nonce:nonce,
      source_id:source_id,
      new_title:$('#new_title').val(),
      new_slug:$('#new_slug').val(),
      status:$('#new_status').val()
    }, function(resp){
      log(resp);
      if(resp && resp.success && resp.data && resp.data.new_id){ $('#ai_page_id').val(resp.data.new_id); $('#selected_ai_page_label').text('Contenu IA sélectionné : ID '+resp.data.new_id+' · '+(resp.data.title || 'brouillon cloné')); }
    });
  });

  function summiteoRest(path, payload){
    return $.ajax({
      url: restRoot + path,
      method: 'POST',
      data: JSON.stringify(payload),
      contentType: 'application/json',
      beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', restNonce); }
    });
  }

  $('#summiteo-ai-preview-btn,#summiteo-ai-apply-btn').on('click', function(e){
    e.preventDefault();
    const apply = this.id === 'summiteo-ai-apply-btn';
    const pageId = $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page ou de l’article à réécrire.'); return; }
    log(apply ? 'Réécriture IA et application en cours...' : 'Prévisualisation IA en cours...');
    summiteoRest('/ai-rewrite-page', {id:pageId, limit:$('#ai_limit').val(), apply:apply})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur API'); });
  });

  $('#summiteo-detect-blocks-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page ou de l’article à analyser.'); return; }
    log('Détection des blocs en cours...');
    summiteoRest('/detect-blocks', {id:pageId})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur détection blocs'); });
  });

  let selectedSummiteoImage = null;
  let selectedUnsplashPhoto = null;

  function escapeHtml(value){ return $('<div>').text(value || '').html(); }

  function renderDetectedImages(images){
    let html = '<div class="summiteo-image-grid">';
    (images || []).forEach(function(img, index){
      const preview = img.url ? '<img src="'+escapeHtml(img.url)+'" alt="">' : '';
      html += '<div class="summiteo-image-card" data-image-index="'+index+'">'+preview+
        '<strong>'+escapeHtml(img.label || img.type || 'Image')+'</strong>'+
        '<div class="summiteo-muted">Type : '+escapeHtml(img.type)+' · ID : '+escapeHtml(img.attachment_id || '')+'</div>'+
        '<div class="summiteo-muted">'+escapeHtml(img.alt || img.title || '')+'</div>'+
        '<button type="button" class="button summiteo-select-detected-image" data-index="'+index+'">Choisir cette image</button>'+
      '</div>';
    });
    html += '</div>';
    $('#summiteo-image-results').html(images && images.length ? html : '<p>Aucune image remplaçable détectée.</p>');
  }

  function renderUnsplashPhotos(photos){
    let html = '<div class="summiteo-image-grid">';
    (photos || []).forEach(function(photo, index){
      const author = photo.user && photo.user.name ? photo.user.name : 'Unsplash';
      html += '<div class="summiteo-image-card" data-photo-index="'+index+'">'+
        '<img src="'+escapeHtml(photo.thumb || photo.regular)+'" alt="">'+
        '<strong>'+escapeHtml(photo.alt || 'Image Unsplash')+'</strong>'+
        '<div class="summiteo-muted">Photo : '+escapeHtml(author)+'</div>'+
        '<button type="button" class="button summiteo-select-unsplash-photo" data-index="'+index+'">Choisir cette photo</button>'+
      '</div>';
    });
    html += '</div>';
    $('#summiteo-unsplash-results').data('photos', photos || []).html(photos && photos.length ? html : '<p>Aucune photo trouvée.</p>');
  }

  $('#summiteo-detect-images-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#image_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page à analyser.'); return; }
    $('#image_page_id').val(pageId);
    $('#summiteo-image-results').html('<p>Détection des images...</p>');
    summiteoRest('/detect-images', {id:pageId})
      .done(function(resp){ selectedSummiteoImage = null; renderDetectedImages(resp.images || []); log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur détection images'); });
  });

  $(document).on('click', '.summiteo-select-detected-image', function(){
    const index = Number($(this).data('index'));
    selectedSummiteoImage = index;
    $('.summiteo-image-card[data-image-index]').removeClass('is-selected');
    $('.summiteo-image-card[data-image-index="'+index+'"]').addClass('is-selected');
  });

  $('#summiteo-unsplash-search-btn').on('click', function(e){
    e.preventDefault();
    const query = $('#unsplash_query').val();
    if(!query){ alert('Indique une recherche Unsplash.'); return; }
    $('#summiteo-unsplash-results').html('<p>Recherche Unsplash...</p>');
    summiteoRest('/search-unsplash', {query:query})
      .done(function(resp){ selectedUnsplashPhoto = null; renderUnsplashPhotos(resp.photos || []); log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur Unsplash'); });
  });

  $(document).on('click', '.summiteo-select-unsplash-photo', function(){
    const index = Number($(this).data('index'));
    const photos = $('#summiteo-unsplash-results').data('photos') || [];
    selectedUnsplashPhoto = photos[index] || null;
    $('.summiteo-image-card[data-photo-index]').removeClass('is-selected');
    $('.summiteo-image-card[data-photo-index="'+index+'"]').addClass('is-selected');
  });

  $('#summiteo-replace-image-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#image_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page.'); return; }
    if(selectedSummiteoImage === null){ alert('Choisis une image détectée.'); return; }
    if(!selectedUnsplashPhoto){ alert('Choisis une photo Unsplash.'); return; }
    log('Import et remplacement de l’image en cours...');
    summiteoRest('/replace-image', {id:pageId, image_index:selectedSummiteoImage, photo:selectedUnsplashPhoto})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur remplacement image'); });
  });

  $('#summiteo-repair-avia-images-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#image_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page.'); return; }
    if(!confirm('Réparer les shortcodes image Avia de cette page ?')){ return; }
    log('Réparation des images Avia en cours...');
    summiteoRest('/repair-avia-images', {id:pageId})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur réparation Avia'); });
  });

  $('#summiteo-sync-avia-editor-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#image_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page.'); return; }
    log('Synchronisation de l’éditeur Avia en cours...');
    summiteoRest('/sync-avia-editor-images', {id:pageId})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur synchronisation Avia'); });
  });

  $('#summiteo-openai-test-btn').on('click', function(e){
    e.preventDefault();
    log('Test de connexion OpenAI en cours...');
    summiteoRest('/test-openai', {})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur test OpenAI'); });
  });
});
JS;
    }

    public function admin_page() {
        $s = self::settings();
        $source = !empty($s['selected_source_id']) ? get_post(absint($s['selected_source_id'])) : null;
        $default_new_title = $source ? $this->apply_replacements(get_the_title($source)) : '';
        $default_new_slug = $source ? sanitize_title($this->apply_replacements($source->post_name)) : '';
        ?>
        <div class="wrap">
                <h1>WP Summiteo <span class="summiteo-pill">v<?php echo esc_html(self::VERSION); ?></span></h1>
            <div class="summiteo-tabs" role="tablist" aria-label="Fonctions WP Summiteo">
                <button type="button" class="summiteo-tab is-active" data-tab="dashboard" role="tab" aria-selected="true">Tableau de bord</button>
                <button type="button" class="summiteo-tab" data-tab="clone" role="tab" aria-selected="false">Clonage</button>
                <button type="button" class="summiteo-tab" data-tab="rewrite" role="tab" aria-selected="false">Réécriture IA</button>
                <button type="button" class="summiteo-tab" data-tab="images" role="tab" aria-selected="false">Images</button>
                <button type="button" class="summiteo-tab" data-tab="settings" role="tab" aria-selected="false">Réglages</button>
            </div>

            <div id="summiteo-tab-dashboard" class="summiteo-tab-panel is-active" role="tabpanel">
            <div class="summiteo-card">
                <h2>Tableau de bord</h2>
            </div>
            </div>

            <div id="summiteo-tab-clone" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Paramètres de clonage</h2>
                <div class="summiteo-row"><label>Ville source</label><input form="wp-summiteo-settings-form" class="regular-text" name="<?php echo self::OPTION; ?>[source_city]" value="<?php echo esc_attr($s['source_city']); ?>"></div>
                <div class="summiteo-row"><label>Ville destination</label><input form="wp-summiteo-settings-form" id="target_city" class="regular-text" name="<?php echo self::OPTION; ?>[target_city]" value="<?php echo esc_attr($s['target_city']); ?>"></div>
                <div class="summiteo-row"><label>Département destination</label><input form="wp-summiteo-settings-form" class="regular-text" name="<?php echo self::OPTION; ?>[target_department]" value="<?php echo esc_attr($s['target_department']); ?>"></div>
                <div class="summiteo-row"><label>Page source sélectionnée</label><div><input form="wp-summiteo-settings-form" id="selected_source_id" class="small-text" name="<?php echo self::OPTION; ?>[selected_source_id]" value="<?php echo esc_attr($s['selected_source_id']); ?>"> <span id="selected_source_label" class="summiteo-muted"><?php echo esc_html($s['selected_source_id'] ? 'ID '.$s['selected_source_id'] : 'Aucune source sélectionnée'); ?></span></div></div>
                <div class="summiteo-row"><label>Remplacements</label><textarea form="wp-summiteo-settings-form" class="large-text" rows="10" name="<?php echo self::OPTION; ?>[replacements]"><?php echo esc_textarea($s['replacements']); ?></textarea><p class="description">Format : source|destination, une règle par ligne. Le remplacement Ville source → Ville destination est ajouté automatiquement, y compris en format slug.</p></div>
                <button type="submit" form="wp-summiteo-settings-form" class="button button-secondary">Enregistrer les paramètres de clonage</button>
            </div>

            <div class="summiteo-card">
                <h2>Sélectionner une page source</h2>
                <p>Recherche une page source, par exemple <strong>La Rochelle</strong>, puis clique sur <strong>Utiliser comme source</strong>.</p>
                <input id="summiteo-source-search" class="regular-text" value="<?php echo esc_attr($s['source_city']); ?>"> <button type="button" id="summiteo-search-btn" class="button">Rechercher</button>
                <div id="summiteo-results" class="summiteo-results"></div>
            </div>

            <div class="summiteo-card">
                <h2>Cloner la source sélectionnée</h2>
                <div class="summiteo-row"><label>Nouveau titre</label><input id="new_title" class="large-text" value="<?php echo esc_attr($default_new_title); ?>" placeholder="Sélectionne une page source pour générer le titre"></div>
                <div class="summiteo-row"><label>Nouveau slug</label><input id="new_slug" class="regular-text" value="<?php echo esc_attr($default_new_slug); ?>" placeholder="Sélectionne une page source pour générer le slug"></div>
                <div class="summiteo-row"><label>Statut</label><select id="new_status"><option value="draft">Brouillon</option><option value="pending">En attente</option><?php if (current_user_can('publish_pages')) echo '<option value="publish">Publié</option>'; ?></select></div>
                <button type="button" id="summiteo-clone-admin-btn" class="button button-primary">Cloner avec titre et slug adaptés</button>
                <p class="description">Le clonage applique les remplacements au titre et au slug uniquement. Le contenu de la page est conservé tel quel.</p>
            </div>
            </div>

            <div id="summiteo-tab-rewrite" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Réécriture IA séparée</h2>
                <p>Cette étape ne modifie pas le clonage Elementor. Sélectionne une page ou un article à réécrire, puis lance une prévisualisation avant application.</p>
                <div class="summiteo-row"><label>Brief éditorial IA</label><textarea form="wp-summiteo-settings-form" class="large-text" rows="8" name="<?php echo self::OPTION; ?>[editorial_brief]" placeholder="Cible, ton, SEO local, contraintes de style, quartiers, CTA, règles HTML..."><?php echo esc_textarea($s['editorial_brief']); ?></textarea></div>
                <button type="submit" form="wp-summiteo-settings-form" class="button button-secondary">Enregistrer le brief éditorial</button>
                <div class="summiteo-row"><label>Rechercher une page ou un article</label><div><input id="summiteo-ai-page-search" class="regular-text" value="<?php echo esc_attr($s['target_city']); ?>"> <button type="button" id="summiteo-ai-search-btn" class="button">Rechercher</button></div></div>
                <div id="summiteo-ai-results" class="summiteo-results"></div>
                <div class="summiteo-row"><label>Contenu IA sélectionné</label><div><input id="ai_page_id" class="small-text" value="<?php echo esc_attr($s['selected_ai_page_id']); ?>"> <span id="selected_ai_page_label" class="summiteo-muted"><?php echo esc_html($s['selected_ai_page_id'] ? 'ID '.$s['selected_ai_page_id'] : 'Aucun contenu sélectionné'); ?></span></div></div>
                <div class="summiteo-row"><label>Nombre de blocs à traiter</label><input id="ai_limit" class="small-text" value="<?php echo esc_attr($s['ai_block_limit']); ?>"> <span class="description">Limite les blocs envoyés à OpenAI pour tester et maîtriser les coûts.</span></div>
                <button type="button" id="summiteo-detect-blocks-btn" class="button">Afficher les blocs détectés</button>
                <button type="button" id="summiteo-ai-preview-btn" class="button">Prévisualiser la réécriture IA</button>
                <button type="button" id="summiteo-ai-apply-btn" class="button button-secondary">Appliquer la réécriture IA au brouillon</button>
                <p class="description">Commencer avec 2 ou 3 blocs, puis vérifier dans Elementor avant de traiter davantage de contenu.</p>
            </div>
            </div>

            <div id="summiteo-tab-images" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Images libres de droits</h2>
                <p>Détecte les images de la page, recherche des alternatives Unsplash, puis remplace uniquement l’image choisie après validation.</p>
                <div class="summiteo-row"><label>Page ou article à analyser</label><div><input id="image_page_id" class="small-text" value="<?php echo esc_attr($s['selected_ai_page_id']); ?>"> <button type="button" id="summiteo-detect-images-btn" class="button">Afficher les images détectées</button></div></div>
                <div id="summiteo-image-results" class="summiteo-results"></div>
                <div class="summiteo-row"><label>Recherche Unsplash</label><div><input id="unsplash_query" class="regular-text" value="<?php echo esc_attr($s['target_city']); ?> immobilier"> <button type="button" id="summiteo-unsplash-search-btn" class="button">Rechercher des images</button></div></div>
                <div id="summiteo-unsplash-results" class="summiteo-results"></div>
                <button type="button" id="summiteo-replace-image-btn" class="button button-secondary">Remplacer l’image sélectionnée</button>
                <button type="button" id="summiteo-repair-avia-images-btn" class="button">Réparer les images Avia</button>
                <button type="button" id="summiteo-sync-avia-editor-btn" class="button">Synchroniser l’éditeur Avia</button>
                <p class="description">Les images sont importées dans la médiathèque. Les images Avia, Elementor et l’image mise en avant sont prises en charge.</p>
            </div>
            </div>

            <div id="summiteo-tab-settings" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Réglages</h2>
                <form id="wp-summiteo-settings-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_group'); ?>
                    <div class="summiteo-row"><label>SEO title modèle</label><input class="large-text" name="<?php echo self::OPTION; ?>[seo_title_tpl]" value="<?php echo esc_attr($s['seo_title_tpl']); ?>"></div>
                    <div class="summiteo-row"><label>SEO description modèle</label><textarea class="large-text" rows="3" name="<?php echo self::OPTION; ?>[seo_desc_tpl]"><?php echo esc_textarea($s['seo_desc_tpl']); ?></textarea></div>
                    <div class="summiteo-row"><label>Activer OpenAI</label><label><input type="checkbox" name="<?php echo self::OPTION; ?>[openai_enabled]" value="1" <?php checked($s['openai_enabled'], '1'); ?>> Autoriser la prévisualisation et la réécriture IA</label></div>
                    <div class="summiteo-row"><label>Clé API OpenAI</label><input class="large-text" type="password" name="<?php echo self::OPTION; ?>[openai_api_key]" value="<?php echo esc_attr($s['openai_api_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Modèle OpenAI</label><input class="regular-text" name="<?php echo self::OPTION; ?>[openai_model]" value="<?php echo esc_attr($s['openai_model']); ?>"></div>
                    <div class="summiteo-row"><label>Connexion OpenAI</label><div><button id="summiteo-openai-test-btn" class="button" type="button">Tester la connexion API</button> <span class="description">Enregistre la clé avant de lancer le test.</span></div></div>
                    <div class="summiteo-row"><label>Clé API Unsplash</label><input class="large-text" type="password" name="<?php echo self::OPTION; ?>[unsplash_access_key]" value="<?php echo esc_attr($s['unsplash_access_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>URL manifeste mise à jour</label><div><input class="large-text" name="<?php echo self::OPTION; ?>[update_manifest_url]" value="<?php echo esc_attr($s['update_manifest_url']); ?>" placeholder="https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json"><p class="description">URL utilisée par WordPress pour proposer les mises à jour automatiques du plugin.</p></div></div>
                    <input type="hidden" name="<?php echo self::OPTION; ?>[ai_block_limit]" value="<?php echo esc_attr($s['ai_block_limit']); ?>">
                    <?php submit_button('Enregistrer les réglages'); ?>
                </form>
            </div>
            </div>

            <div class="summiteo-card">
                <h2>Journal</h2>
                <pre id="summiteo-log" class="summiteo-log">Prêt.</pre>
            </div>
        </div>
        <?php
    }

    public function register_routes() {
        register_rest_route(self::NS, '/ping', [
            'methods' => 'GET', 'callback' => [$this, 'rest_ping'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route(self::NS, '/config', [
            'methods' => 'GET', 'callback' => [$this, 'rest_config'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/find-pages', [
            'methods' => 'GET', 'callback' => [$this, 'rest_find_pages'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/get-page', [
            'methods' => 'GET', 'callback' => [$this, 'rest_get_page'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/extract-blocks', [
            'methods' => 'GET', 'callback' => [$this, 'rest_extract_blocks'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/clone-page', [
            'methods' => 'POST', 'callback' => [$this, 'rest_clone_page'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/update-block', [
            'methods' => 'POST', 'callback' => [$this, 'rest_update_block'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/bulk-update-blocks', [
            'methods' => 'POST', 'callback' => [$this, 'rest_bulk_update_blocks'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/delete-draft', [
            'methods' => 'POST', 'callback' => [$this, 'rest_delete_draft'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/clean-html', [
            'methods' => 'POST', 'callback' => [$this, 'rest_clean_html'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/regenerate-elementor-css', [
            'methods' => 'POST', 'callback' => [$this, 'rest_regenerate_elementor_css'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/ai-rewrite-page', [
            'methods' => 'POST', 'callback' => [$this, 'rest_ai_rewrite_page'], 'permission_callback' => [$this, 'can_use_openai']
        ]);
        register_rest_route(self::NS, '/detect-blocks', [
            'methods' => 'POST', 'callback' => [$this, 'rest_detect_blocks'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/test-openai', [
            'methods' => 'POST', 'callback' => [$this, 'rest_test_openai'], 'permission_callback' => [$this, 'can_use_openai']
        ]);
        register_rest_route(self::NS, '/detect-images', [
            'methods' => 'POST', 'callback' => [$this, 'rest_detect_images'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/search-unsplash', [
            'methods' => 'POST', 'callback' => [$this, 'rest_search_unsplash'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/replace-image', [
            'methods' => 'POST', 'callback' => [$this, 'rest_replace_image'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/repair-avia-images', [
            'methods' => 'POST', 'callback' => [$this, 'rest_repair_avia_images'], 'permission_callback' => [$this, 'can_write']
        ]);
        register_rest_route(self::NS, '/sync-avia-editor-images', [
            'methods' => 'POST', 'callback' => [$this, 'rest_sync_avia_editor_images'], 'permission_callback' => [$this, 'can_write']
        ]);
    }

    public function can_read() {
        if (!is_user_logged_in()) return new WP_Error('summiteo_not_logged_in', 'Authentification requise.', ['status'=>401]);
        if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) return new WP_Error('summiteo_forbidden_cap', 'Droits insuffisants.', ['status'=>403]);
        return true;
    }

    public function can_write() {
        $read = $this->can_read();
        if (is_wp_error($read)) return $read;
        return true;
    }

    public function can_use_openai() {
        $read = $this->can_read();
        if (is_wp_error($read)) return $read;
        if (!current_user_can('manage_options')) return new WP_Error('summiteo_openai_forbidden', 'La génération OpenAI est réservée aux administrateurs.', ['status'=>403]);
        return true;
    }

    public function rest_ping() { return ['success'=>true, 'plugin'=>'WP Summiteo', 'version'=>self::VERSION]; }
    public function rest_config() {
        $config = self::settings();
        $config['has_openai_api_key'] = !empty($config['openai_api_key']);
        $config['has_unsplash_access_key'] = !empty($config['unsplash_access_key']);
        unset($config['openai_api_key']);
        unset($config['unsplash_access_key']);
        return ['success'=>true, 'config'=>$config];
    }

    public function filter_update_plugins($transient) {
        if (!is_object($transient)) return $transient;
        $manifest = $this->get_update_manifest(true);
        if (!$manifest || empty($manifest['version']) || empty($manifest['download_url'])) return $transient;
        if (!version_compare((string)$manifest['version'], self::VERSION, '>')) return $transient;

        $plugin_file = plugin_basename(__FILE__);
        $update = (object)[
            'id' => $plugin_file,
            'slug' => 'wp-summiteo',
            'plugin' => $plugin_file,
            'new_version' => sanitize_text_field($manifest['version']),
            'url' => esc_url_raw($manifest['homepage'] ?? 'https://summiteo.fr'),
            'package' => esc_url_raw($manifest['download_url']),
            'tested' => sanitize_text_field($manifest['tested'] ?? ''),
            'requires' => sanitize_text_field($manifest['requires'] ?? ''),
            'requires_php' => sanitize_text_field($manifest['requires_php'] ?? ''),
        ];
        if (!empty($manifest['icons']) && is_array($manifest['icons'])) $update->icons = $manifest['icons'];
        if (!empty($manifest['banners']) && is_array($manifest['banners'])) $update->banners = $manifest['banners'];
        $transient->response[$plugin_file] = $update;
        return $transient;
    }

    public function filter_plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wp-summiteo') {
            return $result;
        }
        $manifest = $this->get_update_manifest(true);
        if (!$manifest) return $result;
        return (object)[
            'name' => sanitize_text_field($manifest['name'] ?? 'WP Summiteo'),
            'slug' => 'wp-summiteo',
            'version' => sanitize_text_field($manifest['version'] ?? self::VERSION),
            'author' => sanitize_text_field($manifest['author'] ?? 'Summiteo'),
            'author_profile' => esc_url_raw($manifest['author_profile'] ?? ''),
            'homepage' => esc_url_raw($manifest['homepage'] ?? ''),
            'requires' => sanitize_text_field($manifest['requires'] ?? ''),
            'tested' => sanitize_text_field($manifest['tested'] ?? ''),
            'requires_php' => sanitize_text_field($manifest['requires_php'] ?? ''),
            'last_updated' => sanitize_text_field($manifest['last_updated'] ?? ''),
            'download_link' => esc_url_raw($manifest['download_url'] ?? ''),
            'sections' => [
                'description' => wp_kses_post($manifest['description'] ?? 'Plugin métier WP Summiteo.'),
                'changelog' => wp_kses_post($manifest['changelog'] ?? ''),
            ],
        ];
    }

    private function get_update_manifest($force = false) {
        $s = self::settings();
        $url = esc_url_raw($s['update_manifest_url'] ?? '');
        if (!$url) return null;
        $cache_key = 'wp_summiteo_update_manifest_' . md5($url);
        if (!$force) {
            $cached = get_site_transient($cache_key);
            if (is_array($cached)) return $cached;
        }
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) return null;
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) return null;
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) return null;
        set_site_transient($cache_key, $json, 15 * MINUTE_IN_SECONDS);
        return $json;
    }

    public function rest_find_pages(WP_REST_Request $r) {
        $search = sanitize_text_field($r->get_param('search') ?: '');
        return ['success'=>true, 'count'=>count($this->find_pages($search)), 'items'=>$this->find_pages($search)];
    }

    private function find_pages($search) {
        $q = new WP_Query([
            'post_type' => ['page','post'], 'post_status' => ['publish','draft','pending','private'],
            's' => $search, 'posts_per_page' => 30, 'orderby'=>'modified', 'order'=>'DESC'
        ]);
        $items=[];
        foreach ($q->posts as $p) $items[] = $this->page_summary($p);
        return $items;
    }

    private function page_summary($p) {
        return [
            'id'=>$p->ID, 'title'=>html_entity_decode(get_the_title($p), ENT_QUOTES, 'UTF-8'), 'slug'=>$p->post_name,
            'type'=>$p->post_type, 'status'=>$p->post_status, 'modified'=>$p->post_modified,
            'url'=>get_permalink($p), 'edit_url'=>get_edit_post_link($p->ID, ''),
            'elementor_url'=>admin_url('post.php?post='.$p->ID.'&action=elementor'),
            'is_elementor'=>get_post_meta($p->ID, '_elementor_edit_mode', true)==='builder'
        ];
    }

    public function rest_get_page(WP_REST_Request $r) {
        $id = absint($r->get_param('id'));
        $p = get_post($id);
        if (!$p) return new WP_Error('summiteo_not_found','Page introuvable',['status'=>404]);
        $metas = [];
        foreach (['_elementor_data','_elementor_edit_mode','_elementor_template_type','_elementor_version','_elementor_page_settings','_wp_page_template','_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw','rank_math_title','rank_math_description','rank_math_focus_keyword'] as $k) $metas[$k]=get_post_meta($id,$k,true);
        return ['success'=>true,'page'=>array_merge($this->page_summary($p),[
            'content_length'=>strlen($p->post_content), 'excerpt'=>$p->post_excerpt, 'featured_image_id'=>get_post_thumbnail_id($id),
            'parent_id'=>$p->post_parent, 'menu_order'=>$p->menu_order, 'elementor_data_length'=>strlen((string)$metas['_elementor_data']), 'metas'=>$metas
        ])];
    }

    public function rest_extract_blocks(WP_REST_Request $r) {
        $id = absint($r->get_param('id'));
        $data = get_post_meta($id, '_elementor_data', true);
        $json = json_decode($data, true);
        if (!is_array($json)) {
            $post = get_post($id);
            if (!$post || mb_strlen($this->visible_text((string)$post->post_content)) < 45) {
                return new WP_Error('summiteo_no_rewritable_content','Aucun contenu Elementor, article ou page classique suffisamment long à extraire.',['status'=>400]);
            }
            $blocks = [];
            foreach ($this->classic_content_parts((string)$post->post_content) as $i => $part) {
                if (empty($part['rewritable'])) continue;
                $blocks[] = [
                    'element_id'=>'post_content_'.$i,
                    'widget_type'=>'classic-content',
                    'field'=>'post_content.'.$i,
                    'text'=>$part['text'],
                    'plain_text'=>trim(wp_strip_all_tags($part['text'])),
                    'suggested_after_basic_replacements'=>$this->apply_replacements($part['text']),
                ];
            }
            return ['success'=>true,'page_id'=>$id,'mode'=>'classic','count'=>count($blocks),'blocks'=>$blocks];
        }
        $blocks=[]; $this->walk_extract($json, $blocks);
        return ['success'=>true,'page_id'=>$id,'mode'=>'elementor','count'=>count($blocks),'blocks'=>$blocks];
    }

    private function walk_extract($nodes, &$blocks) {
        foreach ($nodes as $node) {
            $id = $node['id'] ?? ''; $wt = $node['widgetType'] ?? ($node['elType'] ?? ''); $settings = $node['settings'] ?? [];
            $fields = ['title','heading_title','editor','text','title_text','description_text'];
            foreach ($fields as $f) if (isset($settings[$f]) && is_string($settings[$f]) && trim(wp_strip_all_tags($settings[$f])) !== '') $blocks[] = $this->block_item($id,$wt,$f,$settings[$f]);
            if (isset($settings['tabs']) && is_array($settings['tabs'])) {
                foreach ($settings['tabs'] as $i=>$tab) foreach (['question','answer'] as $tf) if (!empty($tab[$tf])) $blocks[] = $this->block_item($id,$wt,"tabs.$i.$tf",$tab[$tf]);
            }
            if (!empty($node['elements']) && is_array($node['elements'])) $this->walk_extract($node['elements'], $blocks);
        }
    }

    private function block_item($id,$wt,$field,$text) {
        return ['element_id'=>$id,'widget_type'=>$wt,'field'=>$field,'text'=>$text,'plain_text'=>trim(wp_strip_all_tags($text)),'suggested_after_basic_replacements'=>$this->apply_replacements($text)];
    }

    private function replacement_pairs() {
        $s = self::settings(); $pairs=[];
        $add_pair = function($source, $target) use (&$pairs) {
            $source = trim((string)$source);
            $target = trim((string)$target);
            if ($source !== '' && $target !== '' && $source !== $target) {
                $pairs[$source] = $target;
            }
        };
        $source_city = (string)($s['source_city'] ?? '');
        $target_city = (string)($s['target_city'] ?? '');
        $add_pair($source_city, $target_city);
        $add_pair(sanitize_title($source_city), sanitize_title($target_city));
        if (function_exists('mb_strtolower')) {
            $add_pair(mb_strtolower($source_city, 'UTF-8'), mb_strtolower($target_city, 'UTF-8'));
        } else {
            $add_pair(strtolower($source_city), strtolower($target_city));
        }

        $lines=preg_split('/\r\n|\r|\n/', $s['replacements']);
        foreach ($lines as $line) { if (strpos($line,'|')===false) continue; [$a,$b]=array_map('trim', explode('|',$line,2)); if ($a!=='') $pairs[$a]=$b; }
        return $pairs;
    }
    private function apply_replacements($value) { return strtr((string)$value, $this->replacement_pairs()); }

    private function deep_apply_replacements($value) {
        if (is_string($value)) return $this->apply_replacements($value);
        if (is_array($value)) {
            foreach ($value as $k => $v) $value[$k] = $this->deep_apply_replacements($v);
            return $value;
        }
        return $value;
    }

    private function regenerate_elementor_for_post($post_id) {
        delete_post_meta($post_id, '_elementor_css');
        delete_post_meta($post_id, '_elementor_page_assets');
        if (class_exists('\Elementor\Plugin')) {
            try {
                if (isset(\Elementor\Plugin::$instance->files_manager)) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
                if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                    $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
                    $css_file->update();
                }
            } catch (\Throwable $e) {
                update_post_meta($post_id, '_summiteo_elementor_css_error', $e->getMessage());
            }
        }
    }

    private function elementor_meta_keys() {
        return [
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_page_settings',
            '_elementor_controls_usage',
            '_elementor_css',
            '_elementor_page_assets',
        ];
    }

    private function is_real_elementor_page($post_id) {
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        $data = get_post_meta($post_id, '_elementor_data', true);
        $json = is_string($data) ? json_decode($data, true) : null;
        return $edit_mode === 'builder' && is_array($json) && !empty($json);
    }

    private function cleanup_elementor_meta($post_id) {
        foreach ($this->elementor_meta_keys() as $key) {
            delete_post_meta($post_id, $key);
        }
    }

    public function rest_clone_page(WP_REST_Request $r) { return $this->clone_page_from_params($r->get_json_params() ?: $r->get_params()); }

    private function clone_page_from_params($params) {
        $source_id = absint($params['source_id'] ?? 0); $source = get_post($source_id);
        if (!$source) return new WP_Error('summiteo_not_found','Page source introuvable',['status'=>404]);
        $new_title_raw = isset($params['new_title']) ? trim((string)$params['new_title']) : '';
        $new_slug_raw = isset($params['new_slug']) ? trim((string)$params['new_slug']) : '';
        $new_title = sanitize_text_field($new_title_raw !== '' ? $new_title_raw : $this->apply_replacements(get_the_title($source)));
        $new_slug = sanitize_title($new_slug_raw !== '' ? $new_slug_raw : $this->apply_replacements($source->post_name));
        $status = sanitize_key($params['status'] ?? 'draft');
        if (!in_array($status, ['draft','pending','publish'], true)) $status='draft';
        if ($status==='publish' && !current_user_can('publish_pages')) $status='draft';
        $postarr = [
            'post_type'=>$source->post_type, 'post_status'=>$status, 'post_title'=>$new_title, 'post_name'=>$new_slug,
            'post_content'=>$source->post_content, 'post_excerpt'=>$source->post_excerpt,
            'post_parent'=>0, 'menu_order'=>$source->menu_order, 'post_author'=>get_current_user_id(), 'comment_status'=>$source->comment_status, 'ping_status'=>$source->ping_status
        ];
        $new_id = wp_insert_post($postarr, true);
        if (is_wp_error($new_id)) return $new_id;
        wp_update_post(['ID'=>$new_id, 'post_name'=>$new_slug]);
        $all_meta = get_post_meta($source_id);
        $is_elementor_source = $this->is_real_elementor_page($source_id);
        $elementor_meta_keys = $this->elementor_meta_keys();
        $skip_meta = ['_edit_lock','_edit_last','_wp_old_slug','_elementor_css','_elementor_page_assets'];
        foreach ($all_meta as $key=>$vals) {
            if (in_array($key, $skip_meta, true)) continue;
            if (!$is_elementor_source && in_array($key, $elementor_meta_keys, true)) continue;
            delete_post_meta($new_id,$key);
            foreach ($vals as $v) {
                $maybe = maybe_unserialize($v);
                if ($key === '_elementor_data' && is_string($maybe)) {
                    // Elementor JSON must remain slashed when stored, otherwise WordPress strips escape characters and breaks widgets/styles.
                    update_post_meta($new_id, $key, wp_slash($maybe));
                } else {
                    add_post_meta($new_id, $key, $maybe);
                }
            }
        }
        if ($is_elementor_source) {
            update_post_meta($new_id, '_elementor_edit_mode', 'builder');
            if (!get_post_meta($new_id, '_elementor_template_type', true)) update_post_meta($new_id, '_elementor_template_type', 'wp-page');
        } else {
            $this->cleanup_elementor_meta($new_id);
        }
        if ($thumb = get_post_thumbnail_id($source_id)) set_post_thumbnail($new_id, $thumb);
        if ($is_elementor_source) $this->regenerate_elementor_for_post($new_id);
        clean_post_cache($new_id);
        return ['success'=>true,'source_id'=>$source_id,'new_id'=>$new_id,'title'=>get_the_title($new_id),'slug'=>get_post_field('post_name',$new_id),'status'=>get_post_status($new_id),'url'=>get_permalink($new_id),'edit_url'=>get_edit_post_link($new_id,''),'elementor_url'=>$is_elementor_source ? admin_url('post.php?post='.$new_id.'&action=elementor') : '','is_elementor'=>$is_elementor_source,'css_regenerated'=>$is_elementor_source];
    }

    public function rest_update_block(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id=absint($p['page_id']??0); $element_id=sanitize_text_field($p['element_id']??''); $field=sanitize_text_field($p['field']??''); $new_value=isset($p['value']) ? wp_kses_post($p['value']) : '';
        $data = get_post_meta($page_id,'_elementor_data',true); $json=json_decode($data,true);
        if (!is_array($json)) return new WP_Error('summiteo_bad_elementor','Elementor JSON illisible',['status'=>400]);
        $updated=false; $this->walk_update($json,$element_id,$field,$new_value,$updated);
        if (!$updated) return new WP_Error('summiteo_block_not_found','Bloc introuvable',['status'=>404]);
        update_post_meta($page_id,'_elementor_data',wp_slash(wp_json_encode($json)));
        update_post_meta($page_id,'_elementor_edit_mode','builder');
        $this->regenerate_elementor_for_post($page_id);
        return ['success'=>true,'page_id'=>$page_id,'element_id'=>$element_id,'field'=>$field,'css_regenerated'=>true];
    }

    public function rest_bulk_update_blocks(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['page_id'] ?? 0);
        $updates = isset($p['updates']) && is_array($p['updates']) ? $p['updates'] : [];
        if (!$page_id || empty($updates)) return new WP_Error('summiteo_bad_request','page_id et updates sont requis.',['status'=>400]);

        $data = get_post_meta($page_id,'_elementor_data',true);
        $json = json_decode($data,true);
        if (!is_array($json)) return new WP_Error('summiteo_bad_elementor','Elementor JSON illisible',['status'=>400]);

        $results = [];
        $applied = 0;
        foreach ($updates as $item) {
            $element_id = sanitize_text_field($item['element_id'] ?? '');
            $field = sanitize_text_field($item['field'] ?? '');
            $new_value = isset($item['value']) ? wp_kses_post($item['value']) : '';
            $updated = false;
            if ($element_id !== '' && $field !== '') {
                $this->walk_update($json, $element_id, $field, $new_value, $updated);
            }
            if ($updated) $applied++;
            $results[] = ['element_id'=>$element_id, 'field'=>$field, 'applied'=>$updated];
        }

        if ($applied < 1) return new WP_Error('summiteo_no_blocks_updated','Aucun bloc n’a été mis à jour.',['status'=>404,'results'=>$results]);

        update_post_meta($page_id,'_elementor_data',wp_slash(wp_json_encode($json)));
        update_post_meta($page_id,'_elementor_edit_mode','builder');
        $this->regenerate_elementor_for_post($page_id);
        clean_post_cache($page_id);
        return ['success'=>true,'page_id'=>$page_id,'requested_count'=>count($updates),'applied_count'=>$applied,'results'=>$results,'css_regenerated'=>true];
    }

    private function walk_update(&$nodes,$element_id,$field,$value,&$updated) {
        foreach ($nodes as &$node) {
            if (($node['id']??'') === $element_id) {
                if (strpos($field,'tabs.')===0) { $parts=explode('.',$field); if (count($parts)===3) { $i=(int)$parts[1]; $tf=$parts[2]; if (isset($node['settings']['tabs'][$i][$tf])) { $node['settings']['tabs'][$i][$tf]=$value; $updated=true; } } }
                elseif (isset($node['settings'][$field])) { $node['settings'][$field]=$value; $updated=true; }
            }
            if (!empty($node['elements'])) $this->walk_update($node['elements'],$element_id,$field,$value,$updated);
        }
    }

    public function rest_delete_draft(WP_REST_Request $r) {
        $p=$r->get_json_params() ?: []; $id=absint($p['id']??0); $post=get_post($id);
        if (!$post) return new WP_Error('summiteo_not_found','Brouillon introuvable',['status'=>404]);
        if ($post->post_status !== 'draft') return new WP_Error('summiteo_not_draft','Suppression refusée : ce contenu n\'est pas un brouillon.',['status'=>403]);
        wp_trash_post($id); return ['success'=>true,'trashed_id'=>$id];
    }

    public function rest_clean_html(WP_REST_Request $r) {
        $p=$r->get_json_params() ?: []; $html=(string)($p['html']??'');
        return ['success'=>true,'cleaned'=>$this->clean_html($html)];
    }

    public function rest_regenerate_elementor_css(WP_REST_Request $r) {
        $p=$r->get_json_params() ?: $r->get_params();
        $id = absint($p['id'] ?? 0);
        if (!$id || !get_post($id)) return new WP_Error('summiteo_not_found','Page introuvable',['status'=>404]);
        $this->regenerate_elementor_for_post($id);
        clean_post_cache($id);
        return ['success'=>true,'page_id'=>$id,'css_regenerated'=>true,'elementor_url'=>admin_url('post.php?post='.$id.'&action=elementor')];
    }

    public function rest_detect_images(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        if (!$page_id || !get_post($page_id)) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $images = $this->collect_page_images($page_id);
        return ['success'=>true, 'page_id'=>$page_id, 'count'=>count($images), 'images'=>$images];
    }

    public function rest_search_unsplash(WP_REST_Request $r) {
        $s = self::settings();
        if (empty($s['unsplash_access_key'])) return new WP_Error('summiteo_unsplash_key_missing', 'Clé API Unsplash absente.', ['status'=>400]);
        $p = $r->get_json_params() ?: [];
        $query = trim(sanitize_text_field($p['query'] ?? ''));
        if ($query === '') return new WP_Error('summiteo_unsplash_query_missing', 'Recherche Unsplash vide.', ['status'=>400]);
        $url = add_query_arg([
            'query' => $query,
            'per_page' => 6,
            'orientation' => 'landscape',
            'content_filter' => 'high',
        ], 'https://api.unsplash.com/search/photos');
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Client-ID ' . $s['unsplash_access_key']],
        ]);
        if (is_wp_error($response)) return new WP_Error('summiteo_unsplash_network_error', 'Erreur réseau Unsplash : ' . $response->get_error_message(), ['status'=>502]);
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && isset($json['errors'][0]) ? $json['errors'][0] : $body;
            return new WP_Error('summiteo_unsplash_http_error', 'Erreur Unsplash HTTP ' . $code . ' : ' . $message, ['status'=>502, 'unsplash_status'=>$code]);
        }
        $photos = [];
        foreach (($json['results'] ?? []) as $photo) {
            $photos[] = [
                'id' => sanitize_text_field($photo['id'] ?? ''),
                'alt' => sanitize_text_field($photo['alt_description'] ?? ($photo['description'] ?? '')),
                'thumb' => esc_url_raw($photo['urls']['small'] ?? ''),
                'regular' => esc_url_raw($photo['urls']['regular'] ?? ''),
                'download_location' => esc_url_raw($photo['links']['download_location'] ?? ''),
                'html' => esc_url_raw($photo['links']['html'] ?? ''),
                'user' => [
                    'name' => sanitize_text_field($photo['user']['name'] ?? ''),
                    'html' => esc_url_raw($photo['user']['links']['html'] ?? ''),
                ],
            ];
        }
        return ['success'=>true, 'query'=>$query, 'count'=>count($photos), 'photos'=>$photos];
    }

    public function rest_replace_image(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $image_index = isset($p['image_index']) ? absint($p['image_index']) : -1;
        $photo = is_array($p['photo'] ?? null) ? $p['photo'] : [];
        if (!$page_id || !get_post($page_id)) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $images = $this->collect_page_images($page_id);
        if (!isset($images[$image_index])) return new WP_Error('summiteo_image_not_found', 'Image détectée introuvable.', ['status'=>404]);
        $attachment_id = $this->import_unsplash_photo($photo, $page_id);
        if (is_wp_error($attachment_id)) return $attachment_id;
        $updated = $this->replace_detected_image($page_id, $images[$image_index], $attachment_id);
        if (is_wp_error($updated)) return $updated;
        clean_post_cache($page_id);
        $this->purge_page_cache($page_id);
        $after_images = $this->collect_page_images($page_id);
        return [
            'success' => true,
            'page_id' => $page_id,
            'image' => $images[$image_index],
            'new_attachment_id' => $attachment_id,
            'new_url' => wp_get_attachment_url($attachment_id),
            'updated' => $updated,
            'images_after' => $after_images,
        ];
    }

    private function collect_page_images($page_id) {
        $post = get_post($page_id);
        if (!$post) return [];
        $images = [];
        $thumb_id = get_post_thumbnail_id($page_id);
        if ($thumb_id) {
            $images[] = $this->image_item('featured', 'Image mise en avant', $thumb_id, wp_get_attachment_url($thumb_id), get_post_meta($thumb_id, '_wp_attachment_image_alt', true), get_the_title($thumb_id), ['type'=>'featured']);
        }

        $content = (string)$post->post_content;
        if (preg_match_all('/\[av_image\b[^\]]*\]/is', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $attrs = $this->parse_shortcode_attrs($match[0]);
                $attachment_id = absint($attrs['attachment'] ?? ($attrs['src'] ?? 0));
                $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
                if (!$url && !empty($attrs['src']) && preg_match('#^https?://#i', (string)$attrs['src'])) $url = esc_url_raw($attrs['src']);
                if (!$url) continue;
                $images[] = $this->image_item('avia_image', 'Image Avia #' . ($i + 1), $attachment_id, $url, $attrs['alt'] ?? '', $attrs['title'] ?? '', ['type'=>'avia_image','occurrence'=>$i]);
            }
        }

        $data = get_post_meta($page_id, '_elementor_data', true);
        $json = json_decode($data, true);
        if (is_array($json)) {
            $this->collect_elementor_images($json, $images);
        }
        return array_values($images);
    }

    private function image_item($type, $label, $attachment_id, $url, $alt = '', $title = '', $target = []) {
        return [
            'type' => $type,
            'label' => $label,
            'attachment_id' => absint($attachment_id),
            'url' => esc_url_raw($url),
            'alt' => sanitize_text_field((string)$alt),
            'title' => sanitize_text_field((string)$title),
            'target' => $target,
        ];
    }

    private function collect_elementor_images($nodes, &$images) {
        foreach ($nodes as $node) {
            $id = $node['id'] ?? '';
            $settings = $node['settings'] ?? [];
            foreach (['image','background_image'] as $field) {
                if (!empty($settings[$field]) && is_array($settings[$field])) {
                    $attachment_id = absint($settings[$field]['id'] ?? 0);
                    $url = $attachment_id ? wp_get_attachment_url($attachment_id) : esc_url_raw($settings[$field]['url'] ?? '');
                    if ($url) {
                        $images[] = $this->image_item('elementor_image', 'Image Elementor ' . $field, $attachment_id, $url, '', '', ['type'=>'elementor_image','element_id'=>$id,'field'=>$field]);
                    }
                }
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                $this->collect_elementor_images($node['elements'], $images);
            }
        }
    }

    private function parse_shortcode_attrs($shortcode) {
        $attrs = [];
        if (preg_match_all('/\s([a-z0-9_:-]+)\s*=\s*(["\'])(.*?)\2/is', (string)$shortcode, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) $attrs[strtolower($m[1])] = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attrs;
    }

    private function import_unsplash_photo($photo, $page_id) {
        $s = self::settings();
        if (empty($s['unsplash_access_key'])) return new WP_Error('summiteo_unsplash_key_missing', 'Clé API Unsplash absente.', ['status'=>400]);
        $image_url = esc_url_raw($photo['regular'] ?? '');
        if (!$image_url) return new WP_Error('summiteo_unsplash_photo_invalid', 'Photo Unsplash invalide.', ['status'=>400]);

        $download_location = esc_url_raw($photo['download_location'] ?? '');
        if ($download_location) {
            wp_remote_get($download_location, [
                'timeout' => 10,
                'headers' => ['Authorization' => 'Client-ID ' . $s['unsplash_access_key']],
            ]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) return $tmp;
        $photo_id = sanitize_file_name((string)($photo['id'] ?? uniqid('unsplash-', true)));
        $file = [
            'name' => 'unsplash-' . $photo_id . '.jpg',
            'tmp_name' => $tmp,
        ];
        $caption = trim((string)($photo['alt'] ?? ''));
        $attachment_id = media_handle_sideload($file, $page_id, $caption);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($caption));
        update_post_meta($attachment_id, '_summiteo_unsplash_id', sanitize_text_field($photo['id'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_author', sanitize_text_field($photo['user']['name'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_author_url', esc_url_raw($photo['user']['html'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_photo_url', esc_url_raw($photo['html'] ?? ''));
        return $attachment_id;
    }

    private function replace_detected_image($page_id, $image, $attachment_id) {
        $target = $image['target'] ?? [];
        $type = $target['type'] ?? '';
        if ($type === 'featured') {
            set_post_thumbnail($page_id, $attachment_id);
            return ['type'=>'featured'];
        }
        if ($type === 'avia_image') {
            return $this->replace_avia_image($page_id, absint($target['occurrence'] ?? 0), $attachment_id, $image);
        }
        if ($type === 'elementor_image') {
            return $this->replace_elementor_image($page_id, sanitize_text_field($target['element_id'] ?? ''), sanitize_key($target['field'] ?? ''), $attachment_id);
        }
        return new WP_Error('summiteo_image_type_unsupported', 'Type d’image non pris en charge.', ['status'=>400]);
    }

    private function replace_avia_image($page_id, $occurrence, $attachment_id, $old_image = []) {
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $url = wp_get_attachment_url($attachment_id);
        $old_attachment_id = absint($old_image['attachment_id'] ?? 0);
        $old_url = esc_url_raw($old_image['url'] ?? '');
        $count = 0;
        $matched_shortcode_before = '';
        $matched_shortcode_after = '';
        $new_content = preg_replace_callback('/\[av_image\b[^\]]*\]/is', function($m) use (&$count, $occurrence, $attachment_id, $url) {
            if ($count++ !== $occurrence) return $m[0];
            $GLOBALS['wp_summiteo_matched_avia_before'] = $m[0];
            $attrs = $this->parse_shortcode_attrs($m[0]);
            $src_value = (string)($attrs['src'] ?? '');
            $new_src = (string)$url;
            $shortcode = $m[0];
            if (array_key_exists('src', $attrs) && $new_src !== '') {
                $shortcode = $this->replace_shortcode_attr($shortcode, 'src', $new_src);
            }
            if (array_key_exists('attachment', $attrs)) {
                $shortcode = $this->replace_shortcode_attr($shortcode, 'attachment', (string)$attachment_id);
            }
            $shortcode = $this->remove_shortcode_attr($shortcode, 'size');
            if (strpos($shortcode, 'src=') === false && $url) {
                $shortcode = preg_replace('/\]$/', " src='" . esc_attr($url) . "']", $shortcode);
            }
            $GLOBALS['wp_summiteo_matched_avia_after'] = $shortcode;
            return $shortcode;
        }, (string)$post->post_content);
        $matched_shortcode_before = (string)($GLOBALS['wp_summiteo_matched_avia_before'] ?? '');
        $matched_shortcode_after = (string)($GLOBALS['wp_summiteo_matched_avia_after'] ?? '');
        unset($GLOBALS['wp_summiteo_matched_avia_before'], $GLOBALS['wp_summiteo_matched_avia_after']);

        $result = wp_update_post(['ID'=>$page_id, 'post_content'=>$new_content], true);
        if (is_wp_error($result)) return $result;
        $meta_updates = $this->sync_avia_editor_image_shortcodes($page_id, $new_content);
        clean_post_cache($page_id);
        return [
            'type'=>'avia_image',
            'occurrence'=>$occurrence,
            'content_changed'=>($new_content !== (string)$post->post_content),
            'meta_updates'=>$meta_updates,
            'matched_shortcode_before'=>$matched_shortcode_before,
            'matched_shortcode_after'=>$matched_shortcode_after,
        ];
    }

    private function replace_avia_image_in_meta($page_id, $old_attachment_id, $old_url, $new_attachment_id, $new_url) {
        $updated = [];
        $all_meta = get_post_meta($page_id);
        foreach ($all_meta as $key => $values) {
            if (!$this->is_image_builder_meta_key((string)$key)) {
                continue;
            }
            $new_values = [];
            $key_changed = false;
            foreach ($values as $raw_value) {
                $value = maybe_unserialize($raw_value);
                $changed = false;
                $new_value = $this->replace_image_refs_deep($value, $old_attachment_id, $old_url, $new_attachment_id, $new_url, $changed);
                if (!empty($changed)) {
                    $key_changed = true;
                }
                $new_values[] = $new_value;
            }
            if ($key_changed) {
                delete_post_meta($page_id, $key);
                foreach ($new_values as $new_value) {
                    add_post_meta($page_id, $key, $new_value);
                }
                $updated[] = $key;
            }
        }
        return array_values(array_unique($updated));
    }

    private function is_image_builder_meta_key($key) {
        $key = strtolower((string)$key);
        if ($key === '') return false;
        foreach (['avia', 'alb', 'builder', 'shortcode', 'layout', 'image', 'gallery', 'thumbnail'] as $needle) {
            if (strpos($key, $needle) !== false) return true;
        }
        return false;
    }

    private function replace_image_refs_deep($value, $old_attachment_id, $old_url, $new_attachment_id, $new_url, &$changed = false) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->replace_image_refs_deep($v, $old_attachment_id, $old_url, $new_attachment_id, $new_url, $changed);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = $this->replace_image_refs_deep($v, $old_attachment_id, $old_url, $new_attachment_id, $new_url, $changed);
            }
            return $value;
        }
        if (is_string($value)) {
            $new_value = $value;
            if ($old_attachment_id && $old_attachment_id !== $new_attachment_id) {
                $new_value = str_replace((string)$old_attachment_id, (string)$new_attachment_id, $new_value);
            }
            if ($old_url && $new_url && $old_url !== $new_url) {
                $new_value = str_replace($old_url, $new_url, $new_value);
            }
            if ($new_value !== $value) $changed = true;
            return $new_value;
        }
        if (is_int($value) && $old_attachment_id && $value === $old_attachment_id) {
            $changed = true;
            return $new_attachment_id;
        }
        return $value;
    }

    private function replace_shortcode_attr($shortcode, $attr, $value) {
        $attr_name = (string)$attr;
        $quoted_attr = preg_quote($attr_name, '/');
        $safe_value = esc_attr($value);
        if (preg_match('/\s' . $quoted_attr . '\s*=\s*(["\']).*?\1/is', $shortcode)) {
            return preg_replace_callback('/(\s' . $quoted_attr . '\s*=\s*)(["\']).*?\2/is', function($m) use ($safe_value) {
                return $m[1] . $m[2] . $safe_value . $m[2];
            }, $shortcode, 1);
        }
        return preg_replace('/\]$/', ' ' . $attr_name . "='" . $safe_value . "']", $shortcode);
    }

    private function remove_shortcode_attr($shortcode, $attr) {
        $quoted_attr = preg_quote((string)$attr, '/');
        return preg_replace('/\s' . $quoted_attr . '\s*=\s*(["\']).*?\1/is', '', (string)$shortcode, 1);
    }

    public function rest_repair_avia_images(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);

        $changes = [];
        $index = 0;
        $new_content = preg_replace_callback('/\[av_image\b[^\]]*\]/is', function($m) use (&$changes, &$index) {
            $before = $m[0];
            $attrs = $this->parse_shortcode_attrs($before);
            $attachment_id = absint($attrs['attachment'] ?? 0);
            if (!$attachment_id && !empty($attrs['src']) && preg_match('/^\d+$/', (string)$attrs['src'])) {
                $attachment_id = absint($attrs['src']);
            }
            if (!$attachment_id) {
                $index++;
                return $before;
            }
            $url = wp_get_attachment_url($attachment_id);
            if (!$url) {
                $index++;
                return $before;
            }
            $after = $this->replace_shortcode_attr($before, 'src', $url);
            $after = $this->replace_shortcode_attr($after, 'attachment', (string)$attachment_id);
            $after = $this->remove_shortcode_attr($after, 'size');
            if ($after !== $before) {
                $changes[] = [
                    'index' => $index,
                    'attachment_id' => $attachment_id,
                    'before' => $before,
                    'after' => $after,
                ];
            }
            $index++;
            return $after;
        }, (string)$post->post_content);

        if ($new_content === (string)$post->post_content) {
            return ['success'=>true, 'page_id'=>$page_id, 'changed'=>false, 'changes'=>[]];
        }
        $result = wp_update_post(['ID'=>$page_id, 'post_content'=>$new_content], true);
        if (is_wp_error($result)) return $result;
        $meta_updates = $this->sync_avia_editor_image_shortcodes($page_id, $new_content);
        clean_post_cache($page_id);
        $this->purge_page_cache($page_id);
        return [
            'success' => true,
            'page_id' => $page_id,
            'changed' => true,
            'count' => count($changes),
            'meta_updates' => $meta_updates,
            'changes' => $changes,
        ];
    }

    public function rest_sync_avia_editor_images(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $meta_updates = $this->sync_avia_editor_image_shortcodes($page_id, (string)$post->post_content);
        clean_post_cache($page_id);
        return [
            'success' => true,
            'page_id' => $page_id,
            'meta_updates' => $meta_updates,
        ];
    }

    private function sync_avia_editor_image_shortcodes($page_id, $source_content) {
        $source_shortcodes = $this->avia_image_shortcodes((string)$source_content);
        if (empty($source_shortcodes)) return [];

        $updated = [];
        foreach (['_aviaLayoutBuilderCleanData', '_avia_builder_shortcode_tree', '_avia_builder_shortcode_tree_unfiltered', '_avia_builder_precompile'] as $key) {
            $values = get_post_meta($page_id, $key);
            if (empty($values)) continue;
            $new_values = [];
            $key_changed = false;
            foreach ($values as $raw_value) {
                $value = maybe_unserialize($raw_value);
                $changed = false;
                $new_values[] = $this->sync_avia_image_shortcodes_deep($value, $source_shortcodes, $changed);
                if ($changed) $key_changed = true;
            }
            if ($key_changed) {
                delete_post_meta($page_id, $key);
                foreach ($new_values as $new_value) {
                    add_post_meta($page_id, $key, $new_value);
                }
                $updated[] = $key;
            }
        }
        return $updated;
    }

    private function sync_avia_image_shortcodes_deep($value, $source_shortcodes, &$changed = false) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->sync_avia_image_shortcodes_deep($v, $source_shortcodes, $changed);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = $this->sync_avia_image_shortcodes_deep($v, $source_shortcodes, $changed);
            }
            return $value;
        }
        if (!is_string($value) || strpos($value, '[av_image') === false) return $value;
        $local_shortcodes = $this->avia_image_shortcodes($value);
        if (empty($local_shortcodes) || count($local_shortcodes) !== count($source_shortcodes)) return $value;
        $index = 0;
        $new_value = preg_replace_callback('/\[av_image\b[^\]]*\]/is', function($m) use (&$index, $source_shortcodes) {
            return $source_shortcodes[$index++] ?? $m[0];
        }, $value);
        if ($new_value !== $value) {
            $changed = true;
            return $new_value;
        }
        return $value;
    }

    private function avia_image_shortcodes($content) {
        if (!preg_match_all('/\[av_image\b[^\]]*\]/is', (string)$content, $matches)) return [];
        return $matches[0];
    }

    private function replace_elementor_image($page_id, $element_id, $field, $attachment_id) {
        $data = get_post_meta($page_id, '_elementor_data', true);
        $json = json_decode($data, true);
        if (!is_array($json)) return new WP_Error('summiteo_bad_elementor', 'Elementor JSON illisible.', ['status'=>400]);
        $updated = false;
        $url = wp_get_attachment_url($attachment_id);
        $this->walk_elementor_replace_image($json, $element_id, $field, $attachment_id, $url, $updated);
        if (!$updated) return new WP_Error('summiteo_image_not_found', 'Image Elementor introuvable.', ['status'=>404]);
        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($json)));
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        $this->regenerate_elementor_for_post($page_id);
        $this->purge_page_cache($page_id);
        return ['type'=>'elementor_image', 'element_id'=>$element_id, 'field'=>$field];
    }

    private function purge_page_cache($page_id) {
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($page_id);
        }
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($page_id);
        }
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($page_id);
        }
        do_action('litespeed_purge_post', $page_id);
        $url = get_permalink($page_id);
        if ($url) {
            do_action('litespeed_purge_url', $url);
        }
        $litespeed_purge = 'LiteSpeed\\Purge';
        if (class_exists($litespeed_purge)) {
            if (method_exists($litespeed_purge, 'purge_post')) {
                $litespeed_purge::purge_post($page_id);
            }
            if ($url && method_exists($litespeed_purge, 'purge_url')) {
                $litespeed_purge::purge_url($url);
            }
        }
        if (function_exists('litespeed_purge_post')) {
            litespeed_purge_post($page_id);
        }
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    private function clear_avia_render_cache($page_id) {
        delete_post_meta($page_id, '_avia_builder_shortcode_tree');
        delete_post_meta($page_id, '_avia_builder_shortcode_tree_unfiltered');
        delete_post_meta($page_id, '_aviaLayoutBuilderCleanData');
        delete_post_meta($page_id, '_avia_builder_precompile');
        do_action('ava_after_content_update', $page_id);
        do_action('avia_builder_after_save_post', $page_id);
    }

    private function walk_elementor_replace_image(&$nodes, $element_id, $field, $attachment_id, $url, &$updated) {
        foreach ($nodes as &$node) {
            if (($node['id'] ?? '') === $element_id && isset($node['settings'][$field]) && is_array($node['settings'][$field])) {
                $node['settings'][$field]['id'] = $attachment_id;
                $node['settings'][$field]['url'] = $url;
                $updated = true;
                return;
            }
            if (!empty($node['elements']) && is_array($node['elements'])) {
                $this->walk_elementor_replace_image($node['elements'], $element_id, $field, $attachment_id, $url, $updated);
                if ($updated) return;
            }
        }
    }

    public function rest_test_openai(WP_REST_Request $r) {
        $s = self::settings();
        if (empty($s['openai_api_key'])) {
            return new WP_Error('summiteo_openai_key_missing', 'Clé API OpenAI absente.', ['status'=>400]);
        }
        $started = microtime(true);
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $s['openai_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $s['openai_model'] ?: 'gpt-4.1-mini',
                'input' => 'Réponds uniquement: OK',
                'temperature' => 0,
                'max_output_tokens' => 16,
            ]),
        ]);
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            return new WP_Error('summiteo_openai_network_error', 'Erreur réseau OpenAI : ' . $response->get_error_message(), ['status'=>502, 'elapsed_ms'=>$elapsed_ms]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        $text = $this->extract_openai_text($json);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : $body;
            return new WP_Error('summiteo_openai_http_error', 'Erreur OpenAI HTTP ' . $code . ' : ' . $message, ['status'=>502, 'openai_status'=>$code, 'elapsed_ms'=>$elapsed_ms]);
        }
        return [
            'success' => true,
            'message' => 'Connexion OpenAI OK.',
            'model' => $s['openai_model'] ?: 'gpt-4.1-mini',
            'elapsed_ms' => $elapsed_ms,
            'response_text' => $text,
        ];
    }

    public function rest_detect_blocks(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);

        $data = get_post_meta($page_id, '_elementor_data', true);
        $json = json_decode($data, true);
        $mode = 'classic';
        $blocks = [];

        if (is_array($json)) {
            $elementor_blocks = [];
            $this->walk_extract($json, $elementor_blocks);
            $blocks = array_values(array_filter($elementor_blocks, function($b){
                $plain = $this->visible_text((string)($b['plain_text'] ?? ''));
                if (mb_strlen($plain) < 45) return false;
                if (($b['widget_type'] ?? '') === 'button') return false;
                return true;
            }));
            $mode = !empty($blocks) ? 'elementor' : 'classic';
        }

        if ($mode === 'classic') {
            $mode = $this->is_avia_content((string)$post->post_content) ? 'avia' : 'classic';
            $parts = $this->classic_content_parts((string)$post->post_content);
            foreach ($parts as $i => $part) {
                if (empty($part['rewritable'])) continue;
                $blocks[] = [
                    'element_id' => 'post_content_'.$i,
                    'widget_type' => $mode === 'avia' ? 'avia-content' : 'classic-content',
                    'field' => 'post_content.'.$i,
                    'visible_length' => $this->visible_length($part['text']),
                    'preview' => mb_substr($this->visible_text($part['text']), 0, 180),
                    'source' => ($mode === 'avia' && !empty($part['attribute'])) ? 'avia_attribute' : $mode,
                ];
            }
        } else {
            $blocks = array_map(function($b){
                return [
                    'element_id' => $b['element_id'] ?? '',
                    'widget_type' => $b['widget_type'] ?? '',
                    'field' => $b['field'] ?? '',
                    'visible_length' => $this->visible_length($b['text'] ?? ''),
                    'preview' => mb_substr($this->visible_text($b['text'] ?? ''), 0, 180),
                ];
            }, $blocks);
        }

        return [
            'success' => true,
            'page_id' => $page_id,
            'mode' => $mode,
            'count' => count($blocks),
            'blocks' => $blocks,
        ];
    }

    private function clean_html($html) {
        $html = preg_replace('#<article[^>]*data-testid="conversation-turn-[^>]*>.*?</article>#is','',$html);
        $html = preg_replace('#<div class="text-base.*?</div>\s*</div>\s*</div>\s*</article>#is','',$html);
        // Supprime les attributs parasites souvent issus de copier-coller IA.
        $html = preg_replace('/\sdata-(start|end)="[^"]*"/i', '', (string)$html);
        $html = preg_replace("/\sdata-(start|end)='[^']*'/i", '', (string)$html);
        return trim(wp_kses_post($html));
    }

    private function visible_text($html) {
        $text = html_entity_decode(wp_strip_all_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\x{00A0}/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private function visible_length($html) {
        return mb_strlen($this->visible_text($html));
    }

    private function is_avia_content($content) {
        return strpos((string)$content, '[av_') !== false;
    }

    private function avia_content_parts($content) {
        $content = (string)$content;
        $spans = [];
        $paired_shortcodes = ['av_textblock','av_heading','av_icon_box','av_promobox','av_notification','av_toggle','av_tab_sub_section','av_iconlist_item','av_tab'];
        $pattern = '/(\[(' . implode('|', array_map('preg_quote', $paired_shortcodes)) . ')\b[^\]]*\])(.*?)(\[\/\2\])/is';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $whole) {
                $inner = $matches[3][$index][0];
                $inner_start = $matches[3][$index][1];
                if ($this->visible_length($inner) >= 45) {
                    $spans[] = [
                        'start' => $inner_start,
                        'end' => $inner_start + strlen($inner),
                        'text' => $inner,
                        'rewritable' => true,
                        'attribute' => false,
                    ];
                }
            }
        }

        $media_shortcodes = ['av_image','av_gallery','av_masonry_gallery','av_slideshow','av_slideshow_full','av_video','av_audio'];
        $allowed_attrs = ['heading','title','subtitle','content','text','label','button_label','link_text'];
        if (preg_match_all('/\[([a-z0-9_]+)\b[^\]]*\]/is', $content, $shortcode_matches, PREG_OFFSET_CAPTURE)) {
            foreach ($shortcode_matches[0] as $index => $shortcode_match) {
                $shortcode = strtolower($shortcode_matches[1][$index][0]);
                if (strpos($shortcode, 'av_') !== 0 || in_array($shortcode, $media_shortcodes, true)) {
                    continue;
                }
                $opening = $shortcode_match[0];
                $opening_start = $shortcode_match[1];
                if (!preg_match_all('/\s([a-z0-9_:-]+)\s*=\s*(["\'])(.*?)\2/is', $opening, $attr_matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($attr_matches[1] as $attr_index => $attr_name_match) {
                    $attr_name = strtolower($attr_name_match[0]);
                    if (!in_array($attr_name, $allowed_attrs, true)) {
                        continue;
                    }
                    $value = $attr_matches[3][$attr_index][0];
                    $value_start = $opening_start + $attr_matches[3][$attr_index][1];
                    $plain = $this->visible_text($value);
                    if (mb_strlen($plain) < 8 || !preg_match('/[[:alpha:]]/u', $plain)) {
                        continue;
                    }
                    $spans[] = [
                        'start' => $value_start,
                        'end' => $value_start + strlen($value),
                        'text' => $value,
                        'rewritable' => true,
                        'attribute' => true,
                    ];
                }
            }
        }

        if (empty($spans)) {
            return [];
        }

        usort($spans, function($a, $b) {
            if ($a['start'] === $b['start']) {
                return ($a['end'] - $a['start']) <=> ($b['end'] - $b['start']);
            }
            return $a['start'] <=> $b['start'];
        });

        $filtered_spans = [];
        $last_end = -1;
        foreach ($spans as $span) {
            if ($span['start'] < $last_end) {
                continue;
            }
            $filtered_spans[] = $span;
            $last_end = $span['end'];
        }

        $parts = [];
        $offset = 0;
        foreach ($filtered_spans as $span) {
            $start = $span['start'];
            if ($start > $offset) {
                $parts[] = [
                    'text' => substr($content, $offset, $start - $offset),
                    'rewritable' => false,
                    'prefix' => '',
                    'suffix' => '',
                ];
            }

            $parts[] = [
                'text' => $span['text'],
                'rewritable' => true,
                'prefix' => '',
                'suffix' => '',
                'attribute' => !empty($span['attribute']),
            ];
            $offset = $span['end'];
        }

        if ($offset < strlen($content)) {
            $parts[] = [
                'text' => substr($content, $offset),
                'rewritable' => false,
                'prefix' => '',
                'suffix' => '',
            ];
        }

        return $parts;
    }

    private function classic_content_parts($content) {
        $content = (string)$content;
        if ($this->is_avia_content($content)) {
            $avia_parts = $this->avia_content_parts($content);
            if (!empty($avia_parts)) {
                return $avia_parts;
            }
        }
        $chunks = preg_split('/((?:<\/p>|<\/h[1-6]>|<\/li>|<br\s*\/?>)\s*|(?:\r?\n){2,})/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($chunks) || empty($chunks)) {
            $chunks = [$content];
        }

        $parts = [];
        $current = '';
        foreach ($chunks as $chunk) {
            $current .= $chunk;
            if (preg_match('/^(?:<\/p>|<\/h[1-6]>|<\/li>|<br\s*\/?>|\s+)$/i', trim($chunk)) || preg_match('/(?:<\/p>|<\/h[1-6]>|<\/li>|<br\s*\/?>)\s*$/i', $current)) {
                $parts[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }

        if (count($parts) < 2 && $this->visible_length($content) > 1200) {
            $sentences = preg_split('/(?<=[.!?;:])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
            $parts = [];
            $buffer = '';
            foreach ($sentences as $sentence) {
                $candidate = trim($buffer === '' ? $sentence : $buffer . ' ' . $sentence);
                if ($this->visible_length($candidate) > 900 && $buffer !== '') {
                    $parts[] = $buffer;
                    $buffer = $sentence;
                } else {
                    $buffer = $candidate;
                }
            }
            if (trim($buffer) !== '') {
                $parts[] = $buffer;
            }
        }

        $split_parts = [];
        foreach ($parts as $part) {
            if ($this->visible_length($part) <= 700) {
                $split_parts[] = $part;
                continue;
            }

            $sentences = preg_split('/(?<=[.!?;:])\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($sentences) || count($sentences) < 2) {
                $plain = $this->visible_text($part);
                if (mb_strlen($plain) > 700) {
                    $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
                    $buffer = '';
                    foreach ($words as $word) {
                        $candidate = trim($buffer === '' ? $word : $buffer . ' ' . $word);
                        if (mb_strlen($candidate) > 650 && $buffer !== '') {
                            $split_parts[] = $buffer;
                            $buffer = $word;
                        } else {
                            $buffer = $candidate;
                        }
                    }
                    if (trim($buffer) !== '') {
                        $split_parts[] = $buffer;
                    }
                } else {
                    $split_parts[] = $part;
                }
                continue;
            }

            $buffer = '';
            foreach ($sentences as $sentence) {
                $candidate = trim($buffer === '' ? $sentence : $buffer . ' ' . $sentence);
                if ($this->visible_length($candidate) > 650 && $buffer !== '') {
                    $split_parts[] = $buffer;
                    $buffer = $sentence;
                } else {
                    $buffer = $candidate;
                }
            }
            if (trim($buffer) !== '') {
                $split_parts[] = $buffer;
            }
        }

        $out = [];
        foreach ($split_parts as $part) {
            $visible_len = $this->visible_length($part);
            $out[] = [
                'text' => $part,
                'rewritable' => $visible_len >= 45,
                'prefix' => '',
                'suffix' => '',
            ];
        }
        return $out;
    }


    public function rest_ai_rewrite_page(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $limit = isset($p['limit']) ? max(1, min(30, absint($p['limit']))) : absint(self::settings()['ai_block_limit']);
        $apply = !empty($p['apply']);
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $s = self::settings();
        if ($s['openai_enabled'] !== '1' || empty($s['openai_api_key'])) return new WP_Error('openai_disabled', 'OpenAI est désactivé ou la clé API est absente.', ['status'=>403]);
        if ($apply) {
            $can = $this->can_write();
            if (is_wp_error($can)) return $can;
        }
        $data = get_post_meta($page_id, '_elementor_data', true);
        $json = json_decode($data, true);
        $mode = 'elementor';
        $classic_parts = [];
        if (is_array($json)) {
            $blocks = [];
            $this->walk_extract($json, $blocks);
            $candidates = array_values(array_filter($blocks, function($b){
                $plain = $this->visible_text((string)($b['plain_text'] ?? ''));
                if (mb_strlen($plain) < 45) return false;
                if ($b['widget_type'] === 'button') return false;
                return true;
            }));
            if (empty($candidates) && mb_strlen($this->visible_text((string)$post->post_content)) >= 45) {
                $mode = $this->is_avia_content((string)$post->post_content) ? 'avia' : 'classic';
                $classic_parts = $this->classic_content_parts((string)$post->post_content);
                $candidates = [];
                foreach ($classic_parts as $i => $part) {
                    if (empty($part['rewritable'])) continue;
                    $candidates[] = [
                        'element_id' => 'post_content_'.$i,
                        'widget_type' => $mode === 'avia' ? 'avia-content' : 'classic-content',
                        'field' => 'post_content.'.$i,
                        'part_index' => $i,
                        'text' => $part['text'],
                        'plain_text' => $this->visible_text($part['text']),
                        'attribute' => !empty($part['attribute']),
                    ];
                }
            }
        } else {
            $mode = $this->is_avia_content((string)$post->post_content) ? 'avia' : 'classic';
            $content = (string)$post->post_content;
            if (mb_strlen($this->visible_text($content)) < 45) {
                return new WP_Error('summiteo_no_rewritable_content', 'Aucun contenu de page ou d’article suffisamment long à réécrire.', ['status'=>400]);
            }
            $classic_parts = $this->classic_content_parts($content);
            $candidates = [];
            foreach ($classic_parts as $i => $part) {
                if (empty($part['rewritable'])) continue;
                $candidates[] = [
                    'element_id' => 'post_content_'.$i,
                    'widget_type' => $mode === 'avia' ? 'avia-content' : 'classic-content',
                    'field' => 'post_content.'.$i,
                    'part_index' => $i,
                    'text' => $part['text'],
                    'plain_text' => $this->visible_text($part['text']),
                    'attribute' => !empty($part['attribute']),
                ];
            }
        }
        if (empty($candidates)) {
            return new WP_Error('summiteo_no_rewritable_content', 'Aucun bloc suffisamment long à réécrire.', ['status'=>400]);
        }
        $updates = [];
        $processed = 0;
        $applied_count = 0;
        $skipped_count = 0;
        foreach ($candidates as $b) {
            if ($processed >= $limit) break;
            $rewritten = $this->openai_rewrite_block($b, $s);
            if (is_wp_error($rewritten)) return $rewritten;
            if (trim(wp_strip_all_tags($rewritten)) === '') continue;
            $length_check = $this->length_check($b['text'], $rewritten);
            $item = [
                'element_id' => $b['element_id'],
                'widget_type' => $b['widget_type'],
                'field' => $b['field'],
                'old' => $b['text'],
                'new' => $rewritten,
                'old_length' => $length_check['old_length'],
                'new_length' => $length_check['new_length'],
                'visible_old_length' => $length_check['visible_old_length'],
                'visible_new_length' => $length_check['visible_new_length'],
                'delta_length' => $length_check['delta_length'],
                'delta_percent' => $length_check['delta_percent'],
                'min_length' => $length_check['min_length'],
                'max_length' => $length_check['max_length'],
                'target_length' => $length_check['target_length'],
                'length_ok' => $length_check['ok'],
                'applied' => false,
                'skipped_reason' => '',
            ];
            if ($apply) {
                if (!$length_check['ok']) {
                    $item['skipped_reason'] = 'length_out_of_bounds';
                    $updates[] = $item;
                    $skipped_count++;
                    $processed++;
                    continue;
                }
                $updated = false;
                if ($mode === 'classic' || $mode === 'avia') {
                    $part_index = isset($b['part_index']) ? absint($b['part_index']) : -1;
                    if (isset($classic_parts[$part_index])) {
                        $classic_parts[$part_index]['text'] = !empty($classic_parts[$part_index]['attribute']) ? esc_attr($this->visible_text($rewritten)) : $rewritten;
                        $updated = true;
                    }
                } else {
                    $this->walk_update($json, $b['element_id'], $b['field'], $rewritten, $updated);
                }
                if ($updated) {
                    $item['applied'] = true;
                    $applied_count++;
                } else {
                    $item['skipped_reason'] = 'block_not_found';
                    $skipped_count++;
                }
            }
            $updates[] = $item;
            $processed++;
        }
        if ($apply && $applied_count > 0 && $mode === 'elementor') {
            update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($json)));
            update_post_meta($page_id, '_elementor_edit_mode', 'builder');
            $this->regenerate_elementor_for_post($page_id);
            clean_post_cache($page_id);
        } elseif ($apply && $applied_count > 0) {
            $new_content = implode('', array_map(function($part){
                return ($part['prefix'] ?? '') . $part['text'] . ($part['suffix'] ?? '');
            }, $classic_parts));
            $result = wp_update_post(['ID'=>$page_id, 'post_content'=>$new_content], true);
            if (is_wp_error($result)) return $result;
            clean_post_cache($page_id);
        }
        return ['success'=>true, 'page_id'=>$page_id, 'mode'=>$mode, 'apply'=>$apply, 'count'=>count($updates), 'applied_count'=>$applied_count, 'skipped_count'=>$skipped_count, 'updates'=>$updates];
    }

    private function openai_rewrite_block($block, $settings) {
        $original_html = (string)($block['text'] ?? '');
        $bounds = $this->length_bounds($original_html);
        $is_classic = in_array(($block['widget_type'] ?? ''), ['classic-content','avia-content'], true);
        $max_attempts = $is_classic ? 1 : 10;
        $best_text = '';
        $best_distance = PHP_INT_MAX;
        $last_reason = '';

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $prompt = $this->build_rewrite_prompt($block, $settings, $bounds, $attempt > 1, $last_reason);
            if ($attempt > 1 && $best_text !== '') {
                $best_len = $this->visible_length($best_text);
                $repair_action = $best_len > $bounds['target'] ? 'raccourcis' : 'allonge';
                $prompt .= "\n\nTexte précédent à ajuster :\n" . $best_text . "\n\n" .
                    "Action prioritaire : {$repair_action} ce texte pour viser {$bounds['target']} caractères visibles. " .
                    "Le texte précédent fait {$best_len} caractères visibles. Ne change pas le sens, ne change pas la structure HTML.";
            }

            $text = $this->call_openai_text($prompt, $settings);
            if (is_wp_error($text)) return $text;
            $text = $this->normalise_ai_html($text);
            if (trim(wp_strip_all_tags($text)) === '') continue;

            $check = $this->length_check($original_html, $text);
            $distance = $this->length_distance($bounds, $check['new_length']);
            if ($distance < $best_distance) {
                $best_distance = $distance;
                $best_text = $text;
            }
            if ($check['ok']) {
                return $this->clean_html($text);
            }

            $direction = ($check['new_length'] > $bounds['max']) ? 'raccourcir' : 'allonger';
            $delta = abs((int)$check['new_length'] - (int)$bounds['target']);
            $last_reason = 'Tentative ' . $attempt . ' non conforme. Le texte visible généré fait ' . $check['new_length'] . ' caractères visibles, hors balises HTML. Il faut ' . $direction . ' d\'environ ' . $delta . ' caractères visibles pour rester entre ' . $bounds['min'] . ' et ' . $bounds['max'] . ' caractères, cible idéale ' . $bounds['target'] . '. Ne compte pas les balises HTML. Ne rajoute pas de paragraphe. Ajuste uniquement la densité de la phrase : plus synthétique si trop long, plus précis si trop court.';
        }

        if ($best_text !== '' && !$is_classic) {
            $repaired = $this->openai_repair_length($original_html, $best_text, $block, $settings, $bounds);
            if (!is_wp_error($repaired) && trim(wp_strip_all_tags($repaired)) !== '') {
                $repair_check = $this->length_check($original_html, $repaired);
                $best_check = $this->length_check($original_html, $best_text);
                if ($repair_check['ok'] || abs($repair_check['delta_length']) < abs($best_check['delta_length'])) {
                    return $this->clean_html($repaired);
                }
            }
        }

        return $this->clean_html($best_text);
    }

    private function build_rewrite_prompt($block, $settings, $bounds, $strict_retry = false, $retry_reason = '') {
        $brief = trim((string)($settings['editorial_brief'] ?? ''));
        $plain = $this->visible_text((string)($block['text'] ?? ''));
        $old_len = mb_strlen($plain);
        $profile = $this->html_profile((string)($block['text'] ?? ''));
        $strict = $strict_retry ? "\nRÉÉCRITURE DE CORRECTION : la réponse précédente ne respectait pas la longueur. {$retry_reason}\n" : '';
        $content_label = (($block['widget_type'] ?? '') === 'avia-content') ? 'un texte Avia Builder' : ((($block['widget_type'] ?? '') === 'classic-content') ? 'un contenu WordPress classique' : 'un bloc Elementor');
        $attribute_rule = !empty($block['attribute']) ? "ATTRIBUT AVIA : ce texte sera réinjecté dans un attribut de shortcode. Réponds en texte brut uniquement, sans balise HTML, sans guillemet ouvrant/fermant, sans markdown.\n" : '';
        return "Tu réécris {$content_label} pour une page locale Maison Française de l'Or.\n" .
            "Objectif : vraie adaptation éditoriale locale, pas une simple substitution de ville.\n" .
            $attribute_rule .
            "Préserve les balises HTML utiles existantes comme <p>, <strong>, <br>. Ne renvoie que le HTML final du bloc, sans commentaire, sans markdown.\n" .
            "STRUCTURE STRICTE : conserve le même nombre de paragraphes et de retours ligne que le bloc source. Paragraphes source : {$profile['p_count']}. BR source : {$profile['br_count']}. Si le bloc source n'a pas de balise <p>, n'ajoute pas de balise <p>.\n" .
            "N'ajoute pas de deuxième paragraphe, pas de nouvelle commune, pas de quartier, pas d'exemple produit supplémentaire, sauf si c'est déjà présent dans le texte source.\n" .
            "Respecte l'apostrophe simple si possible. N'utilise aucun emoji ni tiret long.\n" .
            "LONGUEUR IMPÉRATIVE : le texte réécrit doit avoir une longueur très proche du texte source pour ne pas casser la mise en page Elementor.\n" .
            "Texte source hors balises : {$old_len} caractères. Cible prioritaire : {$bounds['target']} caractères visibles. Fourchette acceptée : {$bounds['min']} à {$bounds['max']} caractères visibles.\n" .
            "La longueur se mesure uniquement sur le texte visible, sans compter les balises HTML. Avant de répondre, ajuste mentalement la longueur du texte final.\n" .
            "Si tu ajoutes un détail local, retire une précision ailleurs. Si le texte est trop court, ajoute seulement une précision utile, sans nouveau paragraphe.\n" .
            $strict . "\n" .
            "Brief éditorial IA :\n" . $brief . "\n\n" .
            "Type de widget : " . ($block['widget_type'] ?? '') . "\n" .
            "Champ : " . ($block['field'] ?? '') . "\n" .
            "Texte actuel :\n" . ($block['text'] ?? '');
    }

    private function openai_repair_length($original_html, $candidate_html, $block, $settings, $bounds) {
        $profile = $this->html_profile($original_html);
        $candidate_len = $this->visible_length($candidate_html);
        $direction = $candidate_len > $bounds['target'] ? 'raccourcir' : 'allonger';
        $content_label = (($block['widget_type'] ?? '') === 'avia-content') ? 'texte Avia Builder' : ((($block['widget_type'] ?? '') === 'classic-content') ? 'contenu WordPress classique' : 'bloc Elementor');
        $prompt = "Corrige uniquement la longueur d'un {$content_label} deja reecrit.\n" .
            "Ne fais pas une nouvelle version libre : ajuste le texte fourni.\n" .
            "Action : {$direction} pour viser {$bounds['target']} caracteres visibles. Fourchette obligatoire : {$bounds['min']} a {$bounds['max']} caracteres visibles.\n" .
            "Longueur actuelle du texte a corriger : {$candidate_len} caracteres visibles.\n" .
            "Conserve exactement le meme sens, le meme nombre de paragraphes et la meme structure HTML utile. Paragraphes source : {$profile['p_count']}. BR source : {$profile['br_count']}.\n" .
            "Supprime en priorite les details locaux secondaires, quartiers, communes voisines, exemples produits ajoutes, ou formules redondantes si le texte est trop long.\n" .
            "Si le texte est trop court, ajoute seulement quelques mots utiles, sans nouveau paragraphe.\n" .
            "Ne renvoie que le HTML final, sans commentaire, sans markdown.\n\n" .
            "Type de widget : " . ($block['widget_type'] ?? '') . "\n" .
            "Champ : " . ($block['field'] ?? '') . "\n\n" .
            "Texte source original :\n" . $original_html . "\n\n" .
            "Texte a corriger :\n" . $candidate_html;

        $text = $this->call_openai_text($prompt, $settings);
        if (is_wp_error($text)) return $text;
        return $this->normalise_ai_html($text);
    }

    private function call_openai_text($prompt, $settings) {
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['openai_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $settings['openai_model'] ?: 'gpt-4.1-mini',
                'input' => $prompt,
                'temperature' => 0.1,
                'max_output_tokens' => 1400,
            ]),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) return new WP_Error('openai_error', 'Erreur OpenAI : ' . $body, ['status'=>502]);
        $json = json_decode($body, true);
        $text = $this->extract_openai_text($json);
        if ($text === '') return new WP_Error('openai_empty', 'Réponse OpenAI vide ou illisible.', ['status'=>502]);
        return $text;
    }

    private function normalise_ai_html($text) {
        $text = preg_replace('/^```(?:html)?\s*/i', '', trim((string)$text));
        $text = preg_replace('/\s*```$/', '', $text);
        return $this->clean_html($text);
    }

    private function html_profile($html) {
        $html = (string)$html;
        preg_match_all('/<p\b[^>]*>/i', $html, $p_matches);
        preg_match_all('/<br\b[^>]*>/i', $html, $br_matches);
        return [
            'p_count' => count($p_matches[0]),
            'br_count' => count($br_matches[0]),
        ];
    }

    private function length_bounds($html) {
        $plain = $this->visible_text((string)$html);
        $len = mb_strlen($plain);

        // Tolérance stricte par taille de bloc : l'objectif est une hauteur Elementor très proche.
        if ($len < 90) {
            $min = max(1, $len - 18);
            $max = $len + 18;
        } elseif ($len < 180) {
            $min = (int) floor($len * 0.88);
            $max = (int) ceil($len * 1.12);
        } elseif ($len < 350) {
            $min = (int) floor($len * 0.90);
            $max = (int) ceil($len * 1.10);
        } elseif ($len < 700) {
            $min = (int) floor($len * 0.92);
            $max = (int) ceil($len * 1.08);
        } else {
            $min = (int) floor($len * 0.94);
            $max = (int) ceil($len * 1.06);
        }
        return ['old'=>$len, 'target'=>$len, 'min'=>$min, 'max'=>$max];
    }

    private function length_distance($bounds, $new_len) {
        return abs((int)$new_len - (int)$bounds['target']);
    }

    private function length_check($old_html, $new_html) {
        $bounds = $this->length_bounds($old_html);
        $new_len = $this->visible_length($new_html);
        return [
            'old_length' => $bounds['old'],
            'new_length' => $new_len,
            'visible_old_length' => $bounds['old'],
            'visible_new_length' => $new_len,
            'delta_length' => $new_len - $bounds['target'],
            'delta_percent' => $bounds['target'] > 0 ? round((($new_len - $bounds['target']) / $bounds['target']) * 100, 2) : 0,
            'min_length' => $bounds['min'],
            'max_length' => $bounds['max'],
            'target_length' => $bounds['target'],
            'ok' => ($new_len >= $bounds['min'] && $new_len <= $bounds['max']),
        ];
    }

    private function extract_openai_text($json) {
        if (!is_array($json)) return '';
        if (isset($json['output_text']) && is_string($json['output_text'])) return $json['output_text'];
        if (!empty($json['output']) && is_array($json['output'])) {
            $parts = [];
            foreach ($json['output'] as $item) {
                if (!empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $content) {
                        if (isset($content['text']) && is_string($content['text'])) $parts[] = $content['text'];
                    }
                }
            }
            return trim(implode("\n", $parts));
        }
        return '';
    }

    public function ajax_search_pages() {
        check_ajax_referer('wp_summiteo_admin','nonce');
        if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) wp_send_json_error('Droits insuffisants');
        $search=sanitize_text_field($_POST['search']??'');
        wp_send_json_success(['items'=>$this->find_pages($search)]);
    }

    public function ajax_admin_clone_page() {
        check_ajax_referer('wp_summiteo_admin','nonce');
        $can=$this->can_write();
        if (is_wp_error($can)) wp_send_json_error($can->get_error_message());
        $res=$this->clone_page_from_params($_POST);
        if (is_wp_error($res)) wp_send_json_error($res->get_error_message());
        wp_send_json_success($res);
    }
}

new WP_Summiteo();

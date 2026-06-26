<?php
/**
 * Plugin Name: WP Summiteo
 * Description: Connecteur métier sécurisé pour cloner, adapter et enrichir des contenus WordPress avec l’IA.
 * Version: 82.0.0
 * Author: Summiteo
 * Update URI: https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json
 */

if (!defined('ABSPATH')) { exit; }

class WP_Summiteo {
    const VERSION = '82.0.0';
    const OPTION = 'wp_summiteo_settings';
    const OPTION_GENERAL = 'wp_summiteo_general_settings';
    const OPTION_CLONE = 'wp_summiteo_clone_settings';
    const OPTION_AI = 'wp_summiteo_ai_settings';
    const OPTION_IMAGES = 'wp_summiteo_image_settings';
    const OPTION_PLATFORM = 'wp_summiteo_platform_settings';
    const LEGACY_OPTION = 'goldinfo_ai_connector_settings';
    const NS = 'wp-summiteo/v1';
    const META_SCHEMA_ENABLED = '_wp_summiteo_schema_enabled';
    const META_SCHEMA_TYPE = '_wp_summiteo_schema_type';
    const META_SCHEMA_JSONLD = '_wp_summiteo_schema_jsonld';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_wp_summiteo_search_pages', [$this, 'ajax_search_pages']);
        add_action('wp_ajax_wp_summiteo_admin_clone_page', [$this, 'ajax_admin_clone_page']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_head', [$this, 'print_structured_data_jsonld'], 20);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filter_update_plugins']);
        add_filter('site_transient_update_plugins', [$this, 'filter_update_plugins']);
        add_filter('pre_set_transient_update_plugins', [$this, 'filter_update_plugins']);
        add_filter('transient_update_plugins', [$this, 'filter_update_plugins']);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
    }

    public static function section_defaults($section) {
        $defaults = [
            'general' => [
                'seo_title_tpl' => '{TITLE}',
                'seo_desc_tpl' => '',
                'openai_enabled' => '1',
                'ai_provider' => 'openai',
                'openai_api_key' => '',
                'openai_model' => 'gpt-4.1-mini',
                'claude_api_key' => '',
                'claude_model' => 'claude-opus-4-1-20250805',
                'unsplash_access_key' => '',
                'pexels_api_key' => '',
                'adobe_stock_api_key' => '',
                'enabled_image_sources' => ['unsplash','pexels'],
                'translate_image_filenames' => '0',
                'update_manifest_url' => 'https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json',
            ],
            'clone' => [
                'source_city' => 'La Rochelle',
                'target_city' => 'Bordeaux',
                'target_department' => 'Gironde',
                'selected_source_id' => '',
                'replacements' => "La Rochelle|Bordeaux\nRochelais|Bordelais\nCharente-Maritime|Gironde",
            ],
            'ai' => [
                'selected_ai_page_id' => '',
                'editorial_brief' => '',
                'respect_text_length' => '0',
            ],
            'images' => [
                'selected_image_page_id' => '',
                'default_query' => '',
            ],
            'platform' => [
                'api_url' => '',
                'site_key' => '',
                'site_secret' => '',
                'connected' => '0',
            ],
        ];
        return $defaults[$section] ?? [];
    }

    public static function defaults() {
        return array_merge(
            self::section_defaults('general'),
            self::section_defaults('clone'),
            self::section_defaults('ai'),
            self::section_defaults('images'),
            self::section_defaults('platform')
        );
    }

    public static function settings() {
        $general = wp_parse_args(get_option(self::OPTION_GENERAL, []), self::section_defaults('general'));
        if (empty($general['update_manifest_url'])) {
            $general['update_manifest_url'] = self::section_defaults('general')['update_manifest_url'];
            update_option(self::OPTION_GENERAL, $general, false);
        }
        if (!empty($general['claude_model']) && $general['claude_model'] === 'claude-3-5-sonnet-20241022') {
            $general['claude_model'] = self::section_defaults('general')['claude_model'];
            update_option(self::OPTION_GENERAL, $general, false);
        }
        $general['openai_enabled'] = '1';
        $clone = wp_parse_args(get_option(self::OPTION_CLONE, []), self::section_defaults('clone'));
        $ai = wp_parse_args(get_option(self::OPTION_AI, []), self::section_defaults('ai'));
        $images = wp_parse_args(get_option(self::OPTION_IMAGES, []), self::section_defaults('images'));
        $platform = wp_parse_args(get_option(self::OPTION_PLATFORM, []), self::section_defaults('platform'));
        return array_merge($general, $clone, $ai, $images, $platform);
    }

    public function admin_menu() {
        add_menu_page('WP Summiteo', 'WP Summiteo', 'manage_options', 'wp-summiteo', [$this, 'admin_page'], 'dashicons-superhero-alt', 58);
        add_management_page('WP Summiteo', 'WP Summiteo', 'manage_options', 'wp-summiteo', [$this, 'admin_page']);
        remove_submenu_page('tools.php', 'wp-summiteo');
    }

    public function register_settings() {
        register_setting('wp_summiteo_general_group', self::OPTION_GENERAL, [$this, 'sanitize_general_settings']);
        register_setting('wp_summiteo_clone_group', self::OPTION_CLONE, [$this, 'sanitize_clone_settings']);
        register_setting('wp_summiteo_ai_group', self::OPTION_AI, [$this, 'sanitize_ai_settings']);
        register_setting('wp_summiteo_image_group', self::OPTION_IMAGES, [$this, 'sanitize_image_settings']);
        register_setting('wp_summiteo_platform_group', self::OPTION_PLATFORM, [$this, 'sanitize_platform_settings']);
    }

    public function sanitize_general_settings($input) {
        $input = is_array($input) ? $input : [];
        return [
            'seo_title_tpl' => isset($input['seo_title_tpl']) ? sanitize_text_field($input['seo_title_tpl']) : '{TITLE}',
            'seo_desc_tpl' => isset($input['seo_desc_tpl']) ? sanitize_textarea_field($input['seo_desc_tpl']) : '',
            'openai_enabled' => '1',
            'ai_provider' => $this->sanitize_ai_provider($input['ai_provider'] ?? 'openai'),
            'openai_api_key' => isset($input['openai_api_key']) ? trim(sanitize_text_field($input['openai_api_key'])) : '',
            'openai_model' => isset($input['openai_model']) ? sanitize_text_field($input['openai_model']) : 'gpt-4.1-mini',
            'claude_api_key' => isset($input['claude_api_key']) ? trim(sanitize_text_field($input['claude_api_key'])) : '',
            'claude_model' => isset($input['claude_model']) ? sanitize_text_field($input['claude_model']) : 'claude-opus-4-1-20250805',
            'unsplash_access_key' => isset($input['unsplash_access_key']) ? trim(sanitize_text_field($input['unsplash_access_key'])) : '',
            'pexels_api_key' => isset($input['pexels_api_key']) ? trim(sanitize_text_field($input['pexels_api_key'])) : '',
            'adobe_stock_api_key' => isset($input['adobe_stock_api_key']) ? trim(sanitize_text_field($input['adobe_stock_api_key'])) : '',
            'enabled_image_sources' => $this->sanitize_enabled_image_sources($input['enabled_image_sources'] ?? []),
            'translate_image_filenames' => !empty($input['translate_image_filenames']) ? '1' : '0',
            'update_manifest_url' => isset($input['update_manifest_url']) ? esc_url_raw(trim((string)$input['update_manifest_url'])) : self::section_defaults('general')['update_manifest_url'],
        ];
    }

    private function sanitize_ai_provider($provider) {
        $provider = sanitize_key((string)$provider);
        return in_array($provider, ['openai','claude'], true) ? $provider : 'openai';
    }

    private function sanitize_enabled_image_sources($sources) {
        $allowed = ['unsplash','pexels','adobe_stock'];
        $sources = is_array($sources) ? $sources : [];
        $out = [];
        foreach ($sources as $source) {
            $source = sanitize_key($source);
            if (in_array($source, $allowed, true)) {
                $out[] = $source;
            }
        }
        return array_values(array_unique($out));
    }

    public function sanitize_clone_settings($input) {
        $input = is_array($input) ? $input : [];
        return [
            'source_city' => isset($input['source_city']) ? sanitize_text_field($input['source_city']) : '',
            'target_city' => isset($input['target_city']) ? sanitize_text_field($input['target_city']) : '',
            'target_department' => isset($input['target_department']) ? sanitize_text_field($input['target_department']) : '',
            'selected_source_id' => isset($input['selected_source_id']) ? (string)absint($input['selected_source_id']) : '',
            'replacements' => isset($input['replacements']) ? wp_kses_post($input['replacements']) : '',
        ];
    }

    public function sanitize_ai_settings($input) {
        $input = is_array($input) ? $input : [];
        return [
            'selected_ai_page_id' => isset($input['selected_ai_page_id']) ? (string)absint($input['selected_ai_page_id']) : '',
            'editorial_brief' => isset($input['editorial_brief']) ? wp_kses_post($input['editorial_brief']) : '',
            'respect_text_length' => !empty($input['respect_text_length']) ? '1' : '0',
        ];
    }

    public function sanitize_image_settings($input) {
        $input = is_array($input) ? $input : [];
        return [
            'selected_image_page_id' => isset($input['selected_image_page_id']) ? (string)absint($input['selected_image_page_id']) : '',
            'default_query' => isset($input['default_query']) ? sanitize_text_field($input['default_query']) : '',
        ];
    }

    public function sanitize_platform_settings($input) {
        $input = is_array($input) ? $input : [];
        return [
            'api_url' => isset($input['api_url']) ? esc_url_raw(trim((string)$input['api_url'])) : '',
            'site_key' => isset($input['site_key']) ? sanitize_text_field($input['site_key']) : '',
            'site_secret' => isset($input['site_secret']) ? sanitize_text_field($input['site_secret']) : '',
            'connected' => !empty($input['connected']) ? '1' : '0',
        ];
    }

    public function admin_assets($hook) {
        if (!in_array($hook, ['toplevel_page_wp-summiteo', 'tools_page_wp-summiteo'], true)) { return; }
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', $this->admin_js());
        wp_add_inline_style('wp-admin', $this->admin_css());
    }

    private function admin_css() {
        return '.summiteo-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0;max-width:1100px}.summiteo-tabs{display:flex;gap:6px;margin:18px 0 0;max-width:1100px;border-bottom:1px solid #c3c4c7}.summiteo-tab{appearance:none;background:#f6f7f7;border:1px solid #c3c4c7;border-bottom:0;border-radius:6px 6px 0 0;color:#1d2327;cursor:pointer;font-weight:600;margin:0;padding:10px 14px}.summiteo-tab.is-active{background:#fff;color:#2271b1;box-shadow:inset 0 3px 0 #2271b1}.summiteo-tab-panel{display:none}.summiteo-tab-panel.is-active{display:block}.summiteo-row{display:grid;grid-template-columns:220px 1fr;gap:12px;align-items:center;margin:12px 0}.summiteo-results{margin-top:12px}.summiteo-page{border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:8px 0;background:#fafafa;display:flex;justify-content:space-between;gap:12px}.summiteo-muted{color:#646970}.summiteo-pill{display:inline-block;padding:2px 7px;border-radius:99px;background:#f0f0f1;margin-left:6px}.summiteo-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.summiteo-log{background:#1d2327;color:#f6f7f7;padding:12px;border-radius:6px;white-space:pre-wrap;max-width:1100px;overflow:auto} textarea.large-text{font-family:monospace}.summiteo-image-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-top:12px}.summiteo-image-card{border:1px solid #dcdcde;border-radius:6px;background:#fafafa;padding:10px}.summiteo-image-card img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:4px;background:#f0f0f1}.summiteo-image-card.is-selected{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}.summiteo-image-card strong{display:block;margin:8px 0 4px}.summiteo-image-card .button{margin-top:8px}.summiteo-filename-label{display:block;margin-top:8px;font-weight:600}.summiteo-filename-label input{display:block;margin-top:4px;width:100%;max-width:100%}';
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
  const tabStorageKey = 'wpSummiteoActiveTab';
  function log(msg){ $('#summiteo-log').text(typeof msg === 'string' ? msg : JSON.stringify(msg,null,2)); }
  function activateSummiteoTab(tab, persist){
    const target = $('#summiteo-tab-' + tab);
    const button = $('.summiteo-tab[data-tab="' + tab + '"]');
    if(!target.length || !button.length){ tab = 'dashboard'; }
    $('.summiteo-tab').removeClass('is-active').attr('aria-selected', 'false');
    $('.summiteo-tab[data-tab="' + tab + '"]').addClass('is-active').attr('aria-selected', 'true');
    $('.summiteo-tab-panel').removeClass('is-active');
    $('#summiteo-tab-' + tab).addClass('is-active');
    if(persist !== false){
      try { window.localStorage.setItem(tabStorageKey, tab); } catch(e) {}
      if(window.history && window.history.replaceState){ window.history.replaceState(null, '', '#'+tab); }
    }
  }
  function initialSummiteoTab(){
    const hash = (window.location.hash || '').replace(/^#/, '');
    if(hash && $('.summiteo-tab[data-tab="' + hash + '"]').length){ return hash; }
    try {
      const stored = window.localStorage.getItem(tabStorageKey);
      if(stored && $('.summiteo-tab[data-tab="' + stored + '"]').length){ return stored; }
    } catch(e) {}
    return 'dashboard';
  }
  activateSummiteoTab(initialSummiteoTab(), false);
  $('.summiteo-tab').on('click', function(){
    activateSummiteoTab($(this).data('tab'));
  });
  $('.summiteo-tab-panel form').on('submit', function(){
    const tab = $(this).closest('.summiteo-tab-panel').attr('id').replace('summiteo-tab-', '');
    try { window.localStorage.setItem(tabStorageKey, tab); } catch(e) {}
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
      const actionText = mode === 'ai' ? 'Utiliser pour le contenu' : 'Utiliser comme source';
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
    log(apply ? 'Réécriture du contenu et application en cours...' : 'Prévisualisation du contenu en cours...');
    summiteoRest('/ai-rewrite-page', {id:pageId, apply:apply})
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
  let selectedStockPhoto = null;

  function escapeHtml(value){ return $('<div>').text(value || '').html(); }
  function proposedImageFilename(photo){
    let base = (photo && (photo.alt || photo.title || photo.id)) ? String(photo.alt || photo.title || photo.id) : 'image';
    base = base.normalize ? base.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : base;
    base = base.toLowerCase().replace(/&amp;|&/g, ' et ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').replace(/-{2,}/g, '-');
    if(!base){ base = 'image'; }
    return base.substring(0, 90);
  }
  function proposedImageAlt(photo){
    let base = (photo && (photo.alt || photo.title || photo.filename_source || photo.description || photo.id)) ? String(photo.alt || photo.title || photo.filename_source || photo.description || photo.id) : '';
    base = base.replace(/\.(jpe?g|png|webp|gif)$/i, '').replace(/[_]+/g, ' ').replace(/\s+/g, ' ').trim();
    if(base){ base = base.charAt(0).toLocaleUpperCase() + base.slice(1); }
    return base;
  }

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

  function renderStockPhotos(photos){
    photos = photos || [];
    let html = '<div class="summiteo-image-grid">';
    photos.forEach(function(photo, index){
        const provider = photo.provider || 'stock';
        const author = photo.user && photo.user.name ? photo.user.name : provider;
        const note = photo.license_note ? '<div class="summiteo-muted">'+escapeHtml(photo.license_note)+'</div>' : '';
        photo.proposed_filename = photo.proposed_filename || proposedImageFilename(photo);
        photo.proposed_alt = photo.proposed_alt || proposedImageAlt(photo);
        html += '<div class="summiteo-image-card" data-photo-index="'+index+'">'+
        '<img src="'+escapeHtml(photo.thumb || photo.regular)+'" alt="">'+
        '<strong>'+escapeHtml(photo.alt || 'Image libre de droits')+'</strong>'+
        '<div class="summiteo-muted">'+escapeHtml(provider)+' · Photo : '+escapeHtml(author)+'</div>'+
        '<label class="summiteo-filename-label">Nom du fichier proposé<input type="text" class="regular-text summiteo-photo-filename" data-index="'+index+'" value="'+escapeHtml(photo.proposed_filename)+'"></label>'+
        '<label class="summiteo-filename-label">Balise ALT proposée<input type="text" class="regular-text summiteo-photo-alt" data-index="'+index+'" value="'+escapeHtml(photo.proposed_alt)+'"></label>'+
        note+
        '<button type="button" class="button summiteo-select-stock-photo" data-index="'+index+'">Choisir cette photo</button>'+
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
    if(!query){ alert('Indique une recherche d’image.'); return; }
    $('#summiteo-unsplash-results').html('<p>Recherche dans les sources activées...</p>');
    summiteoRest('/search-stock-images', {query:query})
      .done(function(resp){ selectedStockPhoto = null; renderStockPhotos(resp.photos || []); log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur recherche images'); });
  });

  $(document).on('click', '.summiteo-select-stock-photo', function(){
    const index = Number($(this).data('index'));
    const photos = $('#summiteo-unsplash-results').data('photos') || [];
    selectedStockPhoto = photos[index] || null;
    if(selectedStockPhoto){
      selectedStockPhoto.proposed_filename = $('.summiteo-photo-filename[data-index="'+index+'"]').val() || selectedStockPhoto.proposed_filename || proposedImageFilename(selectedStockPhoto);
      selectedStockPhoto.proposed_alt = $('.summiteo-photo-alt[data-index="'+index+'"]').val() || selectedStockPhoto.proposed_alt || proposedImageAlt(selectedStockPhoto);
    }
    $('.summiteo-image-card[data-photo-index]').removeClass('is-selected');
    $('.summiteo-image-card[data-photo-index="'+index+'"]').addClass('is-selected');
  });

  $(document).on('input', '.summiteo-photo-filename', function(){
    const index = Number($(this).data('index'));
    const photos = $('#summiteo-unsplash-results').data('photos') || [];
    if(photos[index]){
      photos[index].proposed_filename = $(this).val();
      $('#summiteo-unsplash-results').data('photos', photos);
      if(selectedStockPhoto === photos[index]){
        selectedStockPhoto.proposed_filename = $(this).val();
      }
    }
  });
  $(document).on('input', '.summiteo-photo-alt', function(){
    const index = Number($(this).data('index'));
    const photos = $('#summiteo-unsplash-results').data('photos') || [];
    if(photos[index]){
      photos[index].proposed_alt = $(this).val();
      $('#summiteo-unsplash-results').data('photos', photos);
      if(selectedStockPhoto === photos[index]){
        selectedStockPhoto.proposed_alt = $(this).val();
      }
    }
  });

  $('#summiteo-replace-image-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#image_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page.'); return; }
    if(selectedSummiteoImage === null){ alert('Choisis une image détectée.'); return; }
    if(!selectedStockPhoto){ alert('Choisis une photo libre de droits.'); return; }
    const selectedPhotoIndex = $('#summiteo-unsplash-results .summiteo-image-card.is-selected[data-photo-index]').data('photo-index');
    if(selectedPhotoIndex !== undefined){
      selectedStockPhoto.proposed_filename = $('.summiteo-photo-filename[data-index="'+selectedPhotoIndex+'"]').val() || selectedStockPhoto.proposed_filename || proposedImageFilename(selectedStockPhoto);
      selectedStockPhoto.proposed_alt = $('.summiteo-photo-alt[data-index="'+selectedPhotoIndex+'"]').val() || selectedStockPhoto.proposed_alt || proposedImageAlt(selectedStockPhoto);
    }
    log('Import et remplacement de l’image en cours...');
    summiteoRest('/replace-image', {id:pageId, image_index:selectedSummiteoImage, photo:selectedStockPhoto})
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

  function parseSchemaJson(){
    const raw = $('#schema_jsonld').val();
    if(!raw || !raw.trim()){ throw new Error('Le champ JSON-LD est vide.'); }
    const parsed = JSON.parse(raw);
    const first = Array.isArray(parsed) ? parsed[0] : parsed;
    const graph = first && first['@graph'] && first['@graph'][0] ? first['@graph'][0] : first;
    if(!graph || typeof graph !== 'object'){ throw new Error('Le JSON-LD doit contenir un objet.'); }
    if(!graph['@context'] && !first['@context']){ throw new Error('Le champ @context est manquant.'); }
    if(!graph['@type']){ throw new Error('Le champ @type est manquant.'); }
    return parsed;
  }
  function schemaTemplate(type){
    const origin = window.location.origin || '';
    const base = {'@context':'https://schema.org','@type':type,'name':'','url':origin};
    if(type === 'RealEstateAgent'){
      return Object.assign(base, {
        telephone:'',
        areaServed:'',
        address:{'@type':'PostalAddress', streetAddress:'', addressLocality:'', postalCode:'', addressCountry:'FR'}
      });
    }
    if(type === 'Article'){
      return Object.assign(base, {headline:'', description:'', author:{'@type':'Organization','name':''}, datePublished:''});
    }
    if(type === 'FAQPage'){
      return {'@context':'https://schema.org','@type':'FAQPage','mainEntity':[{'@type':'Question','name':'','acceptedAnswer':{'@type':'Answer','text':''}}]};
    }
    if(type === 'BreadcrumbList'){
      return {'@context':'https://schema.org','@type':'BreadcrumbList','itemListElement':[{'@type':'ListItem','position':1,'name':'Accueil','item':origin}]};
    }
    if(type === 'Service'){
      return Object.assign(base, {serviceType:'', areaServed:'', provider:{'@type':'Organization','name':''}});
    }
    if(type === 'Product'){
      return Object.assign(base, {description:'', brand:{'@type':'Brand','name':''}});
    }
    return base;
  }
  $('#summiteo-schema-template-btn').on('click', function(e){
    e.preventDefault();
    const type = $('#schema_type').val() || 'LocalBusiness';
    $('#schema_jsonld').val(JSON.stringify(schemaTemplate(type), null, 2));
    log('Modèle JSON-LD généré. Complète les champs avant enregistrement.');
  });
  $('#summiteo-schema-validate-btn').on('click', function(e){
    e.preventDefault();
    try {
      const parsed = parseSchemaJson();
      log({success:true, message:'JSON-LD valide.', jsonld:parsed});
    } catch(err) {
      log({success:false, message:err.message});
    }
  });
  $('#summiteo-schema-load-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#schema_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page ou de l’article à charger.'); return; }
    $('#schema_page_id').val(pageId);
    log('Chargement des données structurées...');
    summiteoRest('/schema-get', {id:pageId})
      .done(function(resp){
        $('#schema_type').val(resp.schema_type || 'LocalBusiness');
        $('#schema_enabled').prop('checked', resp.enabled !== false && resp.enabled !== '0');
        $('#schema_jsonld').val(resp.jsonld || '');
        log(resp);
      })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur chargement JSON-LD'); });
  });
  $('#summiteo-schema-save-btn').on('click', function(e){
    e.preventDefault();
    const pageId = $('#schema_page_id').val() || $('#ai_page_id').val();
    if(!pageId){ alert('Indique l\'ID de la page ou de l’article à enregistrer.'); return; }
    let parsed;
    try {
      parsed = parseSchemaJson();
    } catch(err) {
      log({success:false, message:err.message});
      return;
    }
    log('Enregistrement du JSON-LD...');
    summiteoRest('/schema-save', {
      id:pageId,
      schema_type:$('#schema_type').val() || 'LocalBusiness',
      enabled:$('#schema_enabled').is(':checked'),
      jsonld:JSON.stringify(parsed, null, 2)
    })
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur sauvegarde JSON-LD'); });
  });

  $('#summiteo-openai-test-btn').on('click', function(e){
    e.preventDefault();
    log('Test de connexion IA en cours...');
    summiteoRest('/test-openai', {})
      .done(function(resp){ log(resp); })
      .fail(function(xhr){ log(xhr.responseJSON || xhr.responseText || 'Erreur test IA'); });
  });
});
JS;
    }

    public function admin_page() {
        $s = self::settings();
        $source = !empty($s['selected_source_id']) ? get_post(absint($s['selected_source_id'])) : null;
        $default_new_title = $source ? $this->apply_replacements(get_the_title($source)) : '';
        $default_new_slug = $source ? sanitize_title($this->apply_replacements($source->post_name)) : '';
        $image_page_id = !empty($s['selected_image_page_id']) ? $s['selected_image_page_id'] : $s['selected_ai_page_id'];
        $image_query = !empty($s['default_query']) ? $s['default_query'] : trim($s['target_city'].' immobilier');
        ?>
        <div class="wrap">
                <h1>WP Summiteo <span class="summiteo-pill">v<?php echo esc_html(self::VERSION); ?></span></h1>
            <div class="summiteo-tabs" role="tablist" aria-label="Fonctions WP Summiteo">
                <button type="button" class="summiteo-tab is-active" data-tab="dashboard" role="tab" aria-selected="true">Tableau de bord</button>
                <button type="button" class="summiteo-tab" data-tab="clone" role="tab" aria-selected="false">Clonage</button>
                <button type="button" class="summiteo-tab" data-tab="rewrite" role="tab" aria-selected="false">Contenu</button>
                <button type="button" class="summiteo-tab" data-tab="images" role="tab" aria-selected="false">Images</button>
                <button type="button" class="summiteo-tab" data-tab="schema" role="tab" aria-selected="false">Données structurées</button>
                <button type="button" class="summiteo-tab" data-tab="platform" role="tab" aria-selected="false">Plateforme</button>
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
                <form id="wp-summiteo-clone-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_clone_group'); ?>
                    <div class="summiteo-row"><label>Ville source</label><input class="regular-text" name="<?php echo self::OPTION_CLONE; ?>[source_city]" value="<?php echo esc_attr($s['source_city']); ?>"></div>
                    <div class="summiteo-row"><label>Ville destination</label><input id="target_city" class="regular-text" name="<?php echo self::OPTION_CLONE; ?>[target_city]" value="<?php echo esc_attr($s['target_city']); ?>"></div>
                    <div class="summiteo-row"><label>Département destination</label><input class="regular-text" name="<?php echo self::OPTION_CLONE; ?>[target_department]" value="<?php echo esc_attr($s['target_department']); ?>"></div>
                    <div class="summiteo-row"><label>Page source sélectionnée</label><div><input id="selected_source_id" class="small-text" name="<?php echo self::OPTION_CLONE; ?>[selected_source_id]" value="<?php echo esc_attr($s['selected_source_id']); ?>"> <span id="selected_source_label" class="summiteo-muted"><?php echo esc_html($s['selected_source_id'] ? 'ID '.$s['selected_source_id'] : 'Aucune source sélectionnée'); ?></span></div></div>
                    <div class="summiteo-row"><label>Remplacements</label><textarea class="large-text" rows="10" name="<?php echo self::OPTION_CLONE; ?>[replacements]"><?php echo esc_textarea($s['replacements']); ?></textarea><p class="description">Format : source|destination, une règle par ligne. Ces remplacements servent au titre, au slug et aux champs SEO.</p></div>
                    <?php submit_button('Enregistrer les paramètres de clonage', 'secondary', 'submit', false); ?>
                </form>
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
                <h2>Contenu</h2>
                <p>Cette étape ne modifie pas le clonage Elementor. Sélectionne une page ou un article à réécrire, puis lance une prévisualisation avant application.</p>
                <form id="wp-summiteo-ai-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_ai_group'); ?>
                    <div class="summiteo-row"><label>Brief éditorial IA</label><textarea class="large-text" rows="8" name="<?php echo self::OPTION_AI; ?>[editorial_brief]" placeholder="Cible, ton, SEO local, contraintes de style, quartiers, CTA, règles HTML..."><?php echo esc_textarea($s['editorial_brief']); ?></textarea></div>
                    <div class="summiteo-row"><label>Contenu IA sélectionné</label><div><input id="ai_page_id" class="small-text" name="<?php echo self::OPTION_AI; ?>[selected_ai_page_id]" value="<?php echo esc_attr($s['selected_ai_page_id']); ?>"> <span id="selected_ai_page_label" class="summiteo-muted"><?php echo esc_html($s['selected_ai_page_id'] ? 'ID '.$s['selected_ai_page_id'] : 'Aucun contenu sélectionné'); ?></span></div></div>
                    <div class="summiteo-row"><label>Respecter la longueur des textes</label><label><input type="checkbox" name="<?php echo self::OPTION_AI; ?>[respect_text_length]" value="1" <?php checked($s['respect_text_length'], '1'); ?>> Appliquer les contrôles de longueur actuels pendant la réécriture</label></div>
                    <?php submit_button('Enregistrer les paramètres IA', 'secondary', 'submit', false); ?>
                </form>
                <div class="summiteo-row"><label>Rechercher une page ou un article</label><div><input id="summiteo-ai-page-search" class="regular-text" value="<?php echo esc_attr($s['target_city']); ?>"> <button type="button" id="summiteo-ai-search-btn" class="button">Rechercher</button></div></div>
                <div id="summiteo-ai-results" class="summiteo-results"></div>
                <button type="button" id="summiteo-detect-blocks-btn" class="button">Afficher les blocs détectés</button>
                <button type="button" id="summiteo-ai-preview-btn" class="button">Prévisualiser la réécriture</button>
                <button type="button" id="summiteo-ai-apply-btn" class="button button-secondary">Appliquer la réécriture au brouillon</button>
                <p class="description">Commencer avec 2 ou 3 blocs, puis vérifier dans Elementor avant de traiter davantage de contenu.</p>
            </div>
            </div>

            <div id="summiteo-tab-images" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Images libres de droits</h2>
                <p>Détecte les images de la page, recherche des alternatives libres de droits, puis remplace uniquement l’image choisie après validation.</p>
                <form id="wp-summiteo-images-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_image_group'); ?>
                    <div class="summiteo-row"><label>Page ou article à analyser</label><div><input id="image_page_id" class="small-text" name="<?php echo self::OPTION_IMAGES; ?>[selected_image_page_id]" value="<?php echo esc_attr($image_page_id); ?>"> <button type="button" id="summiteo-detect-images-btn" class="button">Afficher les images détectées</button></div></div>
                    <div class="summiteo-row"><label>Recherche image</label><div><input id="unsplash_query" class="regular-text" name="<?php echo self::OPTION_IMAGES; ?>[default_query]" value="<?php echo esc_attr($image_query); ?>"> <button type="button" id="summiteo-unsplash-search-btn" class="button">Rechercher des images</button></div></div>
                    <?php submit_button('Enregistrer les paramètres images', 'secondary', 'submit', false); ?>
                </form>
                <div id="summiteo-image-results" class="summiteo-results"></div>
                <div id="summiteo-unsplash-results" class="summiteo-results"></div>
                <button type="button" id="summiteo-replace-image-btn" class="button button-secondary">Remplacer l’image sélectionnée</button>
                <button type="button" id="summiteo-repair-avia-images-btn" class="button">Réparer les images Avia</button>
                <button type="button" id="summiteo-sync-avia-editor-btn" class="button">Synchroniser l’éditeur Avia</button>
                <p class="description">Les images sont importées dans la médiathèque. Les images Avia, Elementor et l’image mise en avant sont prises en charge.</p>
            </div>
            </div>

            <div id="summiteo-tab-schema" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Données structurées</h2>
                <p>Ajoute un JSON-LD spécifique à une page ou un article. Le script est injecté dans le <code>head</code> uniquement sur le contenu ciblé.</p>
                <div class="summiteo-row"><label>Page ou article cible</label><div><input id="schema_page_id" class="small-text" value="<?php echo esc_attr($s['selected_ai_page_id']); ?>"> <button type="button" id="summiteo-schema-load-btn" class="button">Charger</button></div></div>
                <div class="summiteo-row"><label>Type de schema</label><select id="schema_type">
                    <option value="LocalBusiness">LocalBusiness</option>
                    <option value="RealEstateAgent">RealEstateAgent</option>
                    <option value="Organization">Organization</option>
                    <option value="WebPage">WebPage</option>
                    <option value="Article">Article</option>
                    <option value="FAQPage">FAQPage</option>
                    <option value="BreadcrumbList">BreadcrumbList</option>
                    <option value="Service">Service</option>
                    <option value="Product">Product</option>
                </select></div>
                <div class="summiteo-row"><label>Injection active</label><label><input id="schema_enabled" type="checkbox" value="1" checked> Injecter ce JSON-LD sur le contenu cible</label></div>
                <div class="summiteo-row"><label>JSON-LD</label><textarea id="schema_jsonld" class="large-text" rows="16" placeholder='{"@context":"https://schema.org","@type":"LocalBusiness","name":""}'></textarea></div>
                <button type="button" id="summiteo-schema-template-btn" class="button">Générer un modèle</button>
                <button type="button" id="summiteo-schema-validate-btn" class="button">Valider le JSON</button>
                <button type="button" id="summiteo-schema-save-btn" class="button button-secondary">Enregistrer sur la page ou l’article</button>
                <p class="description">Le JSON est validé avant sauvegarde et stocké en meta WordPress sur le contenu sélectionné.</p>
            </div>
            </div>

            <div id="summiteo-tab-platform" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Plateforme Summiteo</h2>
                <p>Préparation du lien entre le plugin WordPress et la future plateforme SaaS Summiteo.</p>
                <form id="wp-summiteo-platform-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_platform_group'); ?>
                    <div class="summiteo-row"><label>URL API plateforme</label><input class="large-text" name="<?php echo self::OPTION_PLATFORM; ?>[api_url]" value="<?php echo esc_attr($s['api_url']); ?>" placeholder="https://app.summiteo.fr/api"></div>
                    <div class="summiteo-row"><label>Clé site</label><input class="regular-text" name="<?php echo self::OPTION_PLATFORM; ?>[site_key]" value="<?php echo esc_attr($s['site_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Secret site</label><input class="large-text" type="password" name="<?php echo self::OPTION_PLATFORM; ?>[site_secret]" value="<?php echo esc_attr($s['site_secret']); ?>" autocomplete="off"></div>
                    <input type="hidden" name="<?php echo self::OPTION_PLATFORM; ?>[connected]" value="<?php echo esc_attr($s['connected']); ?>">
                    <?php submit_button('Enregistrer la configuration plateforme', 'secondary', 'submit', false); ?>
                </form>
            </div>
            </div>

            <div id="summiteo-tab-settings" class="summiteo-tab-panel" role="tabpanel">
            <div class="summiteo-card">
                <h2>Réglages</h2>
                <form id="wp-summiteo-general-form" method="post" action="options.php">
                    <?php settings_fields('wp_summiteo_general_group'); ?>
                    <div class="summiteo-row"><label>SEO title modèle</label><input class="large-text" name="<?php echo self::OPTION_GENERAL; ?>[seo_title_tpl]" value="<?php echo esc_attr($s['seo_title_tpl']); ?>"></div>
                    <div class="summiteo-row"><label>SEO description modèle</label><textarea class="large-text" rows="3" name="<?php echo self::OPTION_GENERAL; ?>[seo_desc_tpl]"><?php echo esc_textarea($s['seo_desc_tpl']); ?></textarea></div>
                    <div class="summiteo-row"><label>Fournisseur IA</label><div>
                        <select name="<?php echo self::OPTION_GENERAL; ?>[ai_provider]">
                            <option value="openai" <?php selected($s['ai_provider'], 'openai'); ?>>OpenAI API</option>
                            <option value="claude" <?php selected($s['ai_provider'], 'claude'); ?>>Claude API</option>
                        </select>
                        <p class="description">Fournisseur utilisé pour la réécriture de contenu et la traduction des noms/ALT d’images.</p>
                    </div></div>
                    <div class="summiteo-row"><label>Clé API OpenAI</label><input class="large-text" type="password" name="<?php echo self::OPTION_GENERAL; ?>[openai_api_key]" value="<?php echo esc_attr($s['openai_api_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Modèle OpenAI</label><input class="regular-text" name="<?php echo self::OPTION_GENERAL; ?>[openai_model]" value="<?php echo esc_attr($s['openai_model']); ?>"></div>
                    <div class="summiteo-row"><label>Clé API Claude</label><input class="large-text" type="password" name="<?php echo self::OPTION_GENERAL; ?>[claude_api_key]" value="<?php echo esc_attr($s['claude_api_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Modèle Claude</label><div><input class="regular-text" name="<?php echo self::OPTION_GENERAL; ?>[claude_model]" value="<?php echo esc_attr($s['claude_model']); ?>"><p class="description">Par défaut : Claude Opus 4.1 (<code>claude-opus-4-1-20250805</code>).</p></div></div>
                    <div class="summiteo-row"><label>Connexion IA</label><div><button id="summiteo-openai-test-btn" class="button" type="button">Tester la connexion API</button> <span class="description">Enregistre la clé du fournisseur sélectionné avant de lancer le test.</span></div></div>
                    <div class="summiteo-row"><label>Sources d’images actives</label><div>
                        <label><input type="checkbox" name="<?php echo self::OPTION_GENERAL; ?>[enabled_image_sources][]" value="unsplash" <?php checked(in_array('unsplash', (array)$s['enabled_image_sources'], true)); ?>> Unsplash</label><br>
                        <label><input type="checkbox" name="<?php echo self::OPTION_GENERAL; ?>[enabled_image_sources][]" value="pexels" <?php checked(in_array('pexels', (array)$s['enabled_image_sources'], true)); ?>> Pexels</label><br>
                        <label><input type="checkbox" name="<?php echo self::OPTION_GENERAL; ?>[enabled_image_sources][]" value="adobe_stock" <?php checked(in_array('adobe_stock', (array)$s['enabled_image_sources'], true)); ?>> Adobe Stock</label>
                    </div></div>
                    <div class="summiteo-row"><label>Traduire les images en français</label><div><label><input type="checkbox" name="<?php echo self::OPTION_GENERAL; ?>[translate_image_filenames]" value="1" <?php checked($s['translate_image_filenames'], '1'); ?>> Traduire en français les noms de fichiers et balises ALT proposés</label><p class="description">Utilise le fournisseur IA sélectionné pendant la recherche d’images. Si aucune clé compatible n’est renseignée, le nom original est simplement nettoyé et localisé autant que possible.</p></div></div>
                    <div class="summiteo-row"><label>Clé API Unsplash</label><input class="large-text" type="password" name="<?php echo self::OPTION_GENERAL; ?>[unsplash_access_key]" value="<?php echo esc_attr($s['unsplash_access_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Clé API Pexels</label><input class="large-text" type="password" name="<?php echo self::OPTION_GENERAL; ?>[pexels_api_key]" value="<?php echo esc_attr($s['pexels_api_key']); ?>" autocomplete="off"></div>
                    <div class="summiteo-row"><label>Clé API Adobe Stock</label><div><input class="large-text" type="password" name="<?php echo self::OPTION_GENERAL; ?>[adobe_stock_api_key]" value="<?php echo esc_attr($s['adobe_stock_api_key']); ?>" autocomplete="off"><p class="description">La recherche Adobe Stock retourne des aperçus. Le téléchargement sans watermark nécessite le workflow de licence Adobe.</p></div></div>
                    <div class="summiteo-row"><label>URL manifeste mise à jour</label><div><input class="large-text" name="<?php echo self::OPTION_GENERAL; ?>[update_manifest_url]" value="<?php echo esc_attr($s['update_manifest_url']); ?>" placeholder="https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json"><p class="description">URL utilisée par WordPress pour proposer les mises à jour automatiques du plugin.</p></div></div>
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
        register_rest_route(self::NS, '/search-stock-images', [
            'methods' => 'POST', 'callback' => [$this, 'rest_search_stock_images'], 'permission_callback' => [$this, 'can_write']
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
        register_rest_route(self::NS, '/schema-get', [
            'methods' => 'POST', 'callback' => [$this, 'rest_schema_get'], 'permission_callback' => [$this, 'can_read']
        ]);
        register_rest_route(self::NS, '/schema-save', [
            'methods' => 'POST', 'callback' => [$this, 'rest_schema_save'], 'permission_callback' => [$this, 'can_write']
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
        if (!current_user_can('manage_options')) return new WP_Error('summiteo_ai_forbidden', 'La génération IA est réservée aux administrateurs.', ['status'=>403]);
        return true;
    }

    private function allowed_schema_types() {
        return ['LocalBusiness','RealEstateAgent','Organization','WebPage','Article','FAQPage','BreadcrumbList','Service','Product'];
    }

    private function sanitize_schema_type($type) {
        $type = sanitize_text_field((string)$type);
        return in_array($type, $this->allowed_schema_types(), true) ? $type : 'LocalBusiness';
    }

    private function validate_schema_jsonld($jsonld) {
        $jsonld = trim((string)$jsonld);
        if ($jsonld === '') {
            return new WP_Error('summiteo_schema_empty', 'Le JSON-LD est vide.', ['status'=>400]);
        }
        $decoded = json_decode($jsonld, true);
        if (!is_array($decoded)) {
            return new WP_Error('summiteo_schema_invalid_json', 'JSON-LD illisible : '.json_last_error_msg(), ['status'=>400]);
        }
        $first = $decoded;
        if (function_exists('array_is_list') && array_is_list($decoded)) {
            $first = $decoded[0] ?? [];
        } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
            $first = $decoded[0] ?? [];
        }
        $graph = $first;
        if (is_array($first) && !empty($first['@graph']) && is_array($first['@graph'])) {
            $graph = $first['@graph'][0] ?? [];
        }
        if (!is_array($graph) || (empty($graph['@context']) && empty($first['@context']))) {
            return new WP_Error('summiteo_schema_missing_context', 'Le champ @context est manquant.', ['status'=>400]);
        }
        if (empty($graph['@type'])) {
            return new WP_Error('summiteo_schema_missing_type', 'Le champ @type est manquant.', ['status'=>400]);
        }
        return $decoded;
    }

    public function rest_schema_get(WP_REST_Request $req) {
        $id = absint($req->get_param('id'));
        if (!$id || !get_post($id)) {
            return new WP_Error('summiteo_schema_not_found', 'Page ou article introuvable.', ['status'=>404]);
        }
        return [
            'success' => true,
            'id' => $id,
            'enabled' => get_post_meta($id, self::META_SCHEMA_ENABLED, true) !== '0',
            'schema_type' => get_post_meta($id, self::META_SCHEMA_TYPE, true) ?: 'LocalBusiness',
            'jsonld' => get_post_meta($id, self::META_SCHEMA_JSONLD, true) ?: '',
        ];
    }

    public function rest_schema_save(WP_REST_Request $req) {
        $id = absint($req->get_param('id'));
        $post = $id ? get_post($id) : null;
        if (!$post) {
            return new WP_Error('summiteo_schema_not_found', 'Page ou article introuvable.', ['status'=>404]);
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('summiteo_schema_forbidden', 'Droits insuffisants pour modifier ce contenu.', ['status'=>403]);
        }
        $jsonld = (string)$req->get_param('jsonld');
        $decoded = $this->validate_schema_jsonld($jsonld);
        if (is_wp_error($decoded)) {
            return $decoded;
        }
        $schema_type = $this->sanitize_schema_type($req->get_param('schema_type'));
        $enabled = $req->get_param('enabled') ? '1' : '0';
        $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!$encoded) {
            return new WP_Error('summiteo_schema_encode_failed', 'Impossible de réencoder le JSON-LD.', ['status'=>500]);
        }
        update_post_meta($id, self::META_SCHEMA_ENABLED, $enabled);
        update_post_meta($id, self::META_SCHEMA_TYPE, $schema_type);
        update_post_meta($id, self::META_SCHEMA_JSONLD, $encoded);
        return [
            'success' => true,
            'id' => $id,
            'enabled' => $enabled === '1',
            'schema_type' => $schema_type,
            'jsonld' => $encoded,
        ];
    }

    public function print_structured_data_jsonld() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $id = get_queried_object_id();
        if (!$id || get_post_meta($id, self::META_SCHEMA_ENABLED, true) === '0') {
            return;
        }
        $jsonld = trim((string)get_post_meta($id, self::META_SCHEMA_JSONLD, true));
        if ($jsonld === '') {
            return;
        }
        $decoded = $this->validate_schema_jsonld($jsonld);
        if (is_wp_error($decoded)) {
            return;
        }
        echo "\n<!-- WP Summiteo JSON-LD -->\n";
        echo '<script type="application/ld+json">'.wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."</script>\n";
    }

    public function rest_ping() { return ['success'=>true, 'plugin'=>'WP Summiteo', 'version'=>self::VERSION]; }
    public function rest_config() {
        $config = self::settings();
        $config['has_openai_api_key'] = !empty($config['openai_api_key']);
        $config['has_claude_api_key'] = !empty($config['claude_api_key']);
        $config['has_unsplash_access_key'] = !empty($config['unsplash_access_key']);
        $config['has_pexels_api_key'] = !empty($config['pexels_api_key']);
        $config['has_adobe_stock_api_key'] = !empty($config['adobe_stock_api_key']);
        unset($config['openai_api_key']);
        unset($config['claude_api_key']);
        unset($config['unsplash_access_key']);
        unset($config['pexels_api_key']);
        unset($config['adobe_stock_api_key']);
        return ['success'=>true, 'config'=>$config];
    }

    public function filter_update_plugins($transient) {
        if (!is_object($transient)) return $transient;
        $manifest = $this->get_update_manifest(true);
        if (!$manifest || empty($manifest['version']) || empty($manifest['download_url'])) return $transient;
        if (!version_compare((string)$manifest['version'], self::VERSION, '>')) return $transient;

        $plugin_file = plugin_basename(__FILE__);
        if (!isset($transient->checked) || !is_array($transient->checked)) {
            $transient->checked = [];
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (isset($transient->no_update) && is_array($transient->no_update)) {
            unset($transient->no_update[$plugin_file]);
        }
        $transient->checked[$plugin_file] = self::VERSION;

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
            'update_uri' => esc_url_raw($manifest['update_uri'] ?? 'https://raw.githubusercontent.com/tanaka38/wp-summiteo/main/update.json'),
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
        $request_url = add_query_arg('_summiteo_t', time(), $url);
        $response = wp_remote_get($request_url, [
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
        $p = $r->get_json_params() ?: $r->get_params();
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
        $p = $r->get_json_params() ?: $r->get_params();
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
        $p = $r->get_json_params() ?: $r->get_params();
        $page_id = absint($p['id'] ?? 0);
        if (!$page_id || !get_post($page_id)) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $images = $this->collect_page_images($page_id);
        return ['success'=>true, 'page_id'=>$page_id, 'count'=>count($images), 'images'=>$images];
    }

    public function rest_search_unsplash(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: $r->get_params();
        $p['provider'] = 'unsplash';
        $request = new WP_REST_Request('POST', '/' . self::NS . '/search-stock-images');
        $request->set_body_params($p);
        return $this->rest_search_stock_images($request);
    }

    public function rest_search_stock_images(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: $r->get_params();
        $provider = sanitize_key($p['provider'] ?? 'unsplash');
        if (empty($p['provider'])) {
            return $this->search_enabled_stock_sources($r);
        }
        if ($provider === 'pexels') {
            return $this->prepare_stock_search_response($this->search_pexels_photos($r));
        }
        if ($provider === 'adobe_stock') {
            return $this->prepare_stock_search_response($this->search_adobe_stock_photos($r));
        }
        return $this->prepare_stock_search_response($this->search_unsplash_photos($r));
    }

    private function search_enabled_stock_sources(WP_REST_Request $r) {
        $s = self::settings();
        $sources = is_array($s['enabled_image_sources'] ?? null) ? $s['enabled_image_sources'] : ['unsplash'];
        if (empty($sources)) {
            return new WP_Error('summiteo_no_stock_source_enabled', 'Aucune source image active. Coche au moins une source dans les reglages.', ['status'=>400]);
        }
        $photos = [];
        $errors = [];
        foreach ($sources as $source) {
            $source = sanitize_key($source);
            if ($source === 'unsplash') {
                $result = $this->search_unsplash_photos($r);
            } elseif ($source === 'pexels') {
                $result = $this->search_pexels_photos($r);
            } elseif ($source === 'adobe_stock') {
                $result = $this->search_adobe_stock_photos($r);
            } else {
                continue;
            }
            if (is_wp_error($result)) {
                $errors[$source] = $result->get_error_message();
                continue;
            }
            foreach (($result['photos'] ?? []) as $photo) {
                $photos[] = $photo;
            }
        }
        if (empty($photos) && !empty($errors)) {
            return new WP_Error('summiteo_stock_sources_failed', 'Aucune source image disponible : ' . implode(' | ', $errors), ['status'=>400, 'sources'=>$errors]);
        }
        $p = $r->get_json_params() ?: $r->get_params();
        return $this->prepare_stock_search_response(['success'=>true, 'provider'=>'enabled', 'sources'=>$sources, 'errors'=>$errors, 'query'=>trim(sanitize_text_field($p['query'] ?? '')), 'count'=>count($photos), 'photos'=>$photos]);
    }

    private function search_unsplash_photos(WP_REST_Request $r) {
        $s = self::settings();
        if (empty($s['unsplash_access_key'])) return new WP_Error('summiteo_unsplash_key_missing', 'Clé API Unsplash absente.', ['status'=>400]);
        $p = $r->get_json_params() ?: $r->get_params();
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
            $alt = sanitize_text_field($photo['alt_description'] ?? '');
            $description = sanitize_text_field($photo['description'] ?? '');
            $slug = sanitize_text_field($photo['slug'] ?? '');
            $filename_source = $alt ?: ($description ?: ($slug ?: $query));
            $photos[] = [
                'provider' => 'Unsplash',
                'provider_key' => 'unsplash',
                'id' => sanitize_text_field($photo['id'] ?? ''),
                'alt' => $alt ?: $description,
                'title' => $alt ?: $description,
                'description' => $description,
                'filename_source' => $filename_source,
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
        return ['success'=>true, 'provider'=>'unsplash', 'query'=>$query, 'count'=>count($photos), 'photos'=>$photos];
    }

    private function search_pexels_photos(WP_REST_Request $r) {
        $s = self::settings();
        if (empty($s['pexels_api_key'])) return new WP_Error('summiteo_pexels_key_missing', 'Clé API Pexels absente.', ['status'=>400]);
        $p = $r->get_json_params() ?: $r->get_params();
        $query = trim(sanitize_text_field($p['query'] ?? ''));
        if ($query === '') return new WP_Error('summiteo_pexels_query_missing', 'Recherche Pexels vide.', ['status'=>400]);
        $url = add_query_arg([
            'query' => $query,
            'per_page' => 6,
            'orientation' => 'landscape',
            'locale' => 'fr-FR',
        ], 'https://api.pexels.com/v1/search');
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['Authorization' => $s['pexels_api_key']],
        ]);
        if (is_wp_error($response)) return new WP_Error('summiteo_pexels_network_error', 'Erreur réseau Pexels : ' . $response->get_error_message(), ['status'=>502]);
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']) ? $json['error'] : $body;
            return new WP_Error('summiteo_pexels_http_error', 'Erreur Pexels HTTP ' . $code . ' : ' . $message, ['status'=>502, 'pexels_status'=>$code]);
        }
        $photos = [];
        foreach (($json['photos'] ?? []) as $photo) {
            $alt = $this->clean_stock_alt_title(sanitize_text_field($photo['alt'] ?? ''), 'pexels');
            $filename_source = $alt ?: $query;
            $photos[] = [
                'provider' => 'Pexels',
                'provider_key' => 'pexels',
                'id' => sanitize_text_field($photo['id'] ?? ''),
                'alt' => $alt,
                'title' => $alt,
                'description' => $alt,
                'filename_source' => $filename_source,
                'thumb' => esc_url_raw($photo['src']['medium'] ?? ''),
                'regular' => esc_url_raw($photo['src']['large2x'] ?? ($photo['src']['large'] ?? '')),
                'html' => esc_url_raw($photo['url'] ?? ''),
                'user' => [
                    'name' => sanitize_text_field($photo['photographer'] ?? ''),
                    'html' => esc_url_raw($photo['photographer_url'] ?? ''),
                ],
            ];
        }
        return ['success'=>true, 'provider'=>'pexels', 'query'=>$query, 'count'=>count($photos), 'photos'=>$photos];
    }

    private function search_adobe_stock_photos(WP_REST_Request $r) {
        $s = self::settings();
        if (empty($s['adobe_stock_api_key'])) return new WP_Error('summiteo_adobe_stock_key_missing', 'Clé API Adobe Stock absente.', ['status'=>400]);
        $p = $r->get_json_params() ?: $r->get_params();
        $query = trim(sanitize_text_field($p['query'] ?? ''));
        if ($query === '') return new WP_Error('summiteo_adobe_stock_query_missing', 'Recherche Adobe Stock vide.', ['status'=>400]);
        $url = add_query_arg([
            'locale' => 'fr_FR',
            'search_parameters[words]' => $query,
            'search_parameters[limit]' => 6,
            'search_parameters[thumbnail_size]' => 1000,
            'search_parameters[filters][content_type:photo]' => 1,
            'search_parameters[filters][orientation]' => 'horizontal',
            'search_parameters[filters][premium]' => 'false',
            'result_columns' => ['id','title','creator_name','thumbnail_500_url','thumbnail_1000_url','comp_url','details_url'],
        ], 'https://stock.adobe.io/Rest/Media/1/Search/Files');
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'x-api-key' => $s['adobe_stock_api_key'],
                'x-Product' => 'WP Summiteo/' . self::VERSION,
            ],
        ]);
        if (is_wp_error($response)) return new WP_Error('summiteo_adobe_stock_network_error', 'Erreur réseau Adobe Stock : ' . $response->get_error_message(), ['status'=>502]);
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) && !empty($json['error']) ? $json['error'] : $body;
            return new WP_Error('summiteo_adobe_stock_http_error', 'Erreur Adobe Stock HTTP ' . $code . ' : ' . $message, ['status'=>502, 'adobe_stock_status'=>$code]);
        }
        $photos = [];
        foreach (($json['files'] ?? []) as $photo) {
            $regular = esc_url_raw($photo['comp_url'] ?? ($photo['thumbnail_1000_url'] ?? ($photo['thumbnail_500_url'] ?? '')));
            $title = sanitize_text_field($photo['title'] ?? '');
            $photos[] = [
                'provider' => 'Adobe Stock',
                'provider_key' => 'adobe_stock',
                'id' => sanitize_text_field($photo['id'] ?? ''),
                'alt' => $title,
                'title' => $title,
                'description' => $title,
                'filename_source' => $title ?: $query,
                'thumb' => esc_url_raw($photo['thumbnail_500_url'] ?? ($photo['thumbnail_1000_url'] ?? $regular)),
                'regular' => $regular,
                'html' => esc_url_raw($photo['details_url'] ?? ''),
                'license_note' => 'Aperçu Adobe Stock. Le fichier final nécessite une licence Adobe.',
                'user' => [
                    'name' => sanitize_text_field($photo['creator_name'] ?? 'Adobe Stock'),
                    'html' => '',
                ],
            ];
        }
        return ['success'=>true, 'provider'=>'adobe_stock', 'query'=>$query, 'count'=>count($photos), 'photos'=>$photos];
    }

    private function prepare_stock_search_response($result) {
        if (is_wp_error($result)) return $result;
        if (!is_array($result)) return $result;
        $photos = is_array($result['photos'] ?? null) ? $result['photos'] : [];
        $result['photos'] = $this->prepare_stock_photo_filenames($photos);
        $result['count'] = count($result['photos']);
        return $result;
    }

    private function prepare_stock_photo_filenames($photos) {
        $settings = self::settings();
        $labels = [];
        foreach ($photos as $photo) {
            $labels[] = $this->stock_photo_label($photo);
        }
        $translated = [];
        if (!empty($settings['translate_image_filenames']) && $settings['translate_image_filenames'] === '1' && $this->has_ai_api_key($settings)) {
            $translated = $this->translate_stock_filename_labels($labels, $settings);
        }
        $translated_alt = [];
        if (!empty($settings['translate_image_filenames']) && $settings['translate_image_filenames'] === '1' && $this->has_ai_api_key($settings)) {
            $translated_alt = $this->translate_stock_alt_labels($labels, $settings);
        }
        foreach ($photos as $index => $photo) {
            $label = trim((string)($translated[$index] ?? $labels[$index] ?? ''));
            if (!empty($settings['translate_image_filenames']) && $settings['translate_image_filenames'] === '1') {
                $label = $this->localise_stock_filename_label($label);
            }
            $alt_label = trim((string)($translated_alt[$index] ?? $labels[$index] ?? ''));
            if (!empty($settings['translate_image_filenames']) && $settings['translate_image_filenames'] === '1') {
                $alt_label = $this->localise_stock_alt_label($alt_label, $photo);
            }
            $photos[$index]['proposed_alt'] = $this->stock_photo_alt_from_label($alt_label, $photo);
            $photos[$index]['proposed_filename'] = $this->stock_photo_filename_slug($label);
        }
        return $photos;
    }

    private function stock_photo_alt_from_label($label, $photo = []) {
        $provider = strtolower(sanitize_key($photo['provider_key'] ?? ($photo['provider'] ?? '')));
        $label = $this->clean_stock_alt_title($label, $provider);
        if ($label === '') {
            foreach (['alt', 'title', 'description', 'filename_source'] as $key) {
                $label = $this->clean_stock_alt_title($photo[$key] ?? '', $provider);
                if ($label !== '') break;
            }
        }
        return sanitize_text_field($this->capitalise_first_letter($label));
    }

    private function localise_stock_alt_label($label, $photo = []) {
        $provider = strtolower(sanitize_key($photo['provider_key'] ?? ($photo['provider'] ?? '')));
        $label = $this->clean_stock_alt_title($label, $provider);
        if ($label === '') return $label;
        $label = str_replace('_', ' ', $label);
        $label = preg_replace('/\s+/', ' ', trim($label));
        return $label;
    }

    private function clean_stock_alt_title($label, $provider = '') {
        $label = wp_strip_all_tags((string)$label);
        $label = preg_replace('/\.(jpe?g|png|webp|gif)$/i', '', $label);
        $label = preg_replace('/\s+/', ' ', trim($label));
        if (strtolower((string)$provider) === 'pexels') {
            $label = preg_replace('/^(?:photos?\s+gratuites?\s+de\s+)+/iu', '', $label);
            $label = preg_replace('/\s+/', ' ', trim($label));
        }
        return $label;
    }

    private function capitalise_first_letter($text) {
        $text = trim((string)$text);
        if ($text === '') return '';
        if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strlen')) {
            return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, mb_strlen($text, 'UTF-8'), 'UTF-8');
        }
        return strtoupper(substr($text, 0, 1)) . substr($text, 1);
    }

    private function stock_photo_label($photo) {
        $label = trim((string)($photo['filename_source'] ?? ''));
        if ($label === '') {
            $label = trim((string)($photo['alt'] ?? ''));
        }
        if ($label === '') {
            $label = trim((string)($photo['title'] ?? ''));
        }
        if ($label === '') {
            $label = trim((string)($photo['description'] ?? ''));
        }
        if ($label === '') {
            $label = trim((string)($photo['id'] ?? 'image'));
        }
        return $label;
    }

    private function translate_stock_filename_labels($labels, $settings) {
        $labels = array_values(array_map(function($label) {
            $label = trim((string)$label);
            return $label !== '' ? $label : 'image';
        }, $labels));
        if (empty($labels)) return [];
        $prompt = "Traduis en francais ces titres d'images pour produire des noms de fichiers SEO courts et naturels.\n" .
            "Contraintes : garde uniquement le sens visuel, pas de ponctuation marketing, pas de numerotation, pas d'extension de fichier.\n" .
            "Si un titre contient deja une requete en francais, reformule-la en nom de fichier court et naturel.\n" .
            "Retourne uniquement un tableau JSON de chaines, dans le meme ordre, avec exactement " . count($labels) . " elements.\n" .
            "Titres :\n" . wp_json_encode($labels, JSON_UNESCAPED_UNICODE);
        $text = $this->call_ai_text($prompt, $settings, ['timeout'=>30, 'temperature'=>0, 'max_tokens'=>1200]);
        if (is_wp_error($text)) return [];
        $text = trim((string)$text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $translations = $this->parse_translation_list($text);
        if (!is_array($translations) || count($translations) !== count($labels)) return [];
        return array_map('sanitize_text_field', $translations);
    }

    private function translate_stock_alt_labels($labels, $settings) {
        $labels = array_values(array_map(function($label) {
            $label = trim((string)$label);
            return $label !== '' ? $label : 'image';
        }, $labels));
        if (empty($labels)) return [];
        $prompt = "Traduis en francais ces titres d'images pour des balises ALT WordPress lisibles.\n" .
            "Contraintes strictes : conserve le titre dans son integralite, garde les determinants et tous les mots utiles, conserve les accents, ne transforme jamais en slug, n'utilise pas d'underscore, pas d'extension de fichier.\n" .
            "Si le titre est deja en francais, nettoie seulement les prefixes parasites et conserve une phrase naturelle.\n" .
            "Retourne uniquement un tableau JSON de chaines, dans le meme ordre, avec exactement " . count($labels) . " elements.\n" .
            "Titres :\n" . wp_json_encode($labels, JSON_UNESCAPED_UNICODE);
        $text = $this->call_ai_text($prompt, $settings, ['timeout'=>30, 'temperature'=>0, 'max_tokens'=>1600]);
        if (is_wp_error($text)) return [];
        $text = trim((string)$text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $translations = $this->parse_translation_list($text);
        if (!is_array($translations) || count($translations) !== count($labels)) return [];
        return array_map('sanitize_text_field', $translations);
    }

    private function parse_translation_list($text) {
        $text = trim((string)$text);
        $translations = json_decode($text, true);
        if (is_array($translations)) {
            if (isset($translations['translations']) && is_array($translations['translations'])) {
                return array_values($translations['translations']);
            }
            return array_values($translations);
        }
        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $translations = json_decode($m[0], true);
            if (is_array($translations)) return array_values($translations);
        }
        $lines = preg_split('/\R+/', $text);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = preg_replace('/^\s*(?:[-*]|\d+[\).\-\|:])\s*/', '', $line);
            $line = trim($line, " \t\n\r\0\x0B\"'");
            if ($line !== '') $out[] = $line;
        }
        return $out;
    }

    private function localise_stock_filename_label($label) {
        $label = trim((string)$label);
        if ($label === '') return $label;
        $lower = strtolower($label);
        $dictionary = [
            'aerial view' => 'vue aerienne',
            'body of water' => 'plan d eau',
            'during daytime' => 'en journee',
            'during day' => 'en journee',
            'snow covered' => 'enneige',
            'snowy landscape' => 'paysage enneige',
            'mountain range' => 'massif montagneux',
            'mountains' => 'montagnes',
            'mountain' => 'montagne',
            'trees' => 'arbres',
            'tree' => 'arbre',
            'water' => 'eau',
            'lake' => 'lac',
            'landscape' => 'paysage',
            'foreground' => 'premier plan',
            'background' => 'arriere plan',
            'green' => 'vert',
            'white boat' => 'bateau blanc',
            'boat' => 'bateau',
            'dock' => 'quai',
            'sitting next to' => 'pres de',
            'next to' => 'pres de',
            'view' => 'vue',
            'near' => 'pres de',
            'with' => 'avec',
            'and' => 'et',
            'of' => 'de',
        ];
        $lower = preg_replace('/\b(a|an|the)\b/', '', $lower);
        foreach ($dictionary as $english => $french) {
            $lower = str_replace($english, $french, $lower);
        }
        $lower = preg_replace('/\s+/', ' ', trim($lower));
        return $lower !== '' ? $lower : $label;
    }

    public function rest_replace_image(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $image_index = isset($p['image_index']) ? absint($p['image_index']) : -1;
        $photo = is_array($p['photo'] ?? null) ? $p['photo'] : [];
        if (!$page_id || !get_post($page_id)) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $images = $this->collect_page_images($page_id);
        if (!isset($images[$image_index])) return new WP_Error('summiteo_image_not_found', 'Image détectée introuvable.', ['status'=>404]);
        $attachment_id = $this->import_stock_photo($photo, $page_id);
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

    private function import_stock_photo($photo, $page_id) {
        $provider = strtolower(sanitize_key($photo['provider_key'] ?? ($photo['provider'] ?? 'unsplash')));
        if ($provider === 'adobestock') {
            $provider = 'adobe_stock';
        }
        if ($provider === 'pexels') {
            return $this->import_pexels_photo($photo, $page_id);
        }
        if ($provider === 'adobe_stock') {
            return $this->import_adobe_stock_photo($photo, $page_id);
        }
        return $this->import_unsplash_photo($photo, $page_id);
    }

    private function stock_photo_filename($photo, $fallback_prefix) {
        $source = trim((string)($photo['proposed_filename'] ?? ''));
        if ($source === '') {
            $source = trim((string)($photo['alt'] ?? ''));
        }
        if ($source === '') {
            $source = trim((string)($photo['title'] ?? ''));
        }
        if ($source === '') {
            $source = $fallback_prefix . '-' . (string)($photo['id'] ?? wp_generate_uuid4());
        }
        $filename = $this->stock_photo_filename_slug($source);
        if ($filename === '') {
            $filename = sanitize_file_name($fallback_prefix . '-' . (string)($photo['id'] ?? wp_generate_uuid4()));
        }
        return $filename . '.jpg';
    }

    private function stock_photo_filename_slug($source) {
        $source = preg_replace('/\.(jpe?g|png|webp|gif)$/i', '', (string)$source);
        $filename = sanitize_title($source);
        if ($filename === '') {
            $filename = 'image';
        }
        return substr($filename, 0, 90);
    }

    private function stock_photo_alt_text($photo) {
        $provider = strtolower(sanitize_key($photo['provider_key'] ?? ($photo['provider'] ?? '')));
        foreach (['proposed_alt', 'alt', 'title', 'description', 'filename_source'] as $key) {
            $value = $this->clean_stock_alt_title($photo[$key] ?? '', $provider);
            if ($value !== '') {
                return sanitize_text_field($this->capitalise_first_letter($value));
            }
        }
        return '';
    }

    private function update_attachment_alt_text($attachment_id, $alt_text) {
        $alt_text = sanitize_text_field($alt_text);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        if ($alt_text !== '') {
            wp_update_post([
                'ID' => $attachment_id,
                'post_title' => $alt_text,
                'post_excerpt' => $alt_text,
            ]);
        }
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
        $file = [
            'name' => $this->stock_photo_filename($photo, 'unsplash'),
            'tmp_name' => $tmp,
        ];
        $caption = $this->stock_photo_alt_text($photo);
        $attachment_id = media_handle_sideload($file, $page_id, $caption);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        $this->update_attachment_alt_text($attachment_id, $caption);
        update_post_meta($attachment_id, '_summiteo_unsplash_id', sanitize_text_field($photo['id'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_author', sanitize_text_field($photo['user']['name'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_author_url', esc_url_raw($photo['user']['html'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_unsplash_photo_url', esc_url_raw($photo['html'] ?? ''));
        return $attachment_id;
    }

    private function import_pexels_photo($photo, $page_id) {
        $image_url = esc_url_raw($photo['regular'] ?? '');
        if (!$image_url) return new WP_Error('summiteo_pexels_photo_invalid', 'Photo Pexels invalide.', ['status'=>400]);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) return $tmp;
        $file = [
            'name' => $this->stock_photo_filename($photo, 'pexels'),
            'tmp_name' => $tmp,
        ];
        $caption = $this->stock_photo_alt_text($photo);
        $attachment_id = media_handle_sideload($file, $page_id, $caption);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        $this->update_attachment_alt_text($attachment_id, $caption);
        update_post_meta($attachment_id, '_summiteo_pexels_id', sanitize_text_field($photo['id'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_pexels_author', sanitize_text_field($photo['user']['name'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_pexels_author_url', esc_url_raw($photo['user']['html'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_pexels_photo_url', esc_url_raw($photo['html'] ?? ''));
        return $attachment_id;
    }

    private function import_adobe_stock_photo($photo, $page_id) {
        $image_url = esc_url_raw($photo['regular'] ?? '');
        if (!$image_url) return new WP_Error('summiteo_adobe_stock_photo_invalid', 'Photo Adobe Stock invalide.', ['status'=>400]);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) return $tmp;
        $file = [
            'name' => $this->stock_photo_filename($photo, 'adobe-stock-preview'),
            'tmp_name' => $tmp,
        ];
        $caption = $this->stock_photo_alt_text($photo);
        $attachment_id = media_handle_sideload($file, $page_id, $caption);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        $this->update_attachment_alt_text($attachment_id, $caption);
        update_post_meta($attachment_id, '_summiteo_adobe_stock_id', sanitize_text_field($photo['id'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_adobe_stock_author', sanitize_text_field($photo['user']['name'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_adobe_stock_photo_url', esc_url_raw($photo['html'] ?? ''));
        update_post_meta($attachment_id, '_summiteo_adobe_stock_license_note', sanitize_text_field($photo['license_note'] ?? 'Aperçu Adobe Stock. Licence requise pour usage final.'));
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
        $alt_text = sanitize_text_field(get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        $old_attachment_id = absint($old_image['attachment_id'] ?? 0);
        $old_url = esc_url_raw($old_image['url'] ?? '');
        $count = 0;
        $matched_shortcode_before = '';
        $matched_shortcode_after = '';
        $new_content = preg_replace_callback('/\[av_image\b[^\]]*\]/is', function($m) use (&$count, $occurrence, $attachment_id, $url, $alt_text) {
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
            if ($alt_text !== '') {
                $shortcode = $this->replace_shortcode_attr($shortcode, 'alt', $alt_text);
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
        $provider = $this->selected_ai_provider($s);
        if (!$this->has_ai_api_key($s)) {
            $label = $provider === 'claude' ? 'Claude' : 'OpenAI';
            return new WP_Error('summiteo_ai_key_missing', 'Clé API ' . $label . ' absente.', ['status'=>400, 'provider'=>$provider]);
        }
        $started = microtime(true);
        $text = $this->call_ai_text('Réponds uniquement: OK', $s, ['timeout'=>15, 'temperature'=>0, 'max_tokens'=>32]);
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($text)) return $text;
        return [
            'success' => true,
            'message' => 'Connexion IA OK.',
            'provider' => $provider,
            'model' => $provider === 'claude' ? ($s['claude_model'] ?: 'claude-opus-4-1-20250805') : ($s['openai_model'] ?: 'gpt-4.1-mini'),
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
        $visible_len = $this->visible_length($content);
        return [[
            'text' => $content,
            'rewritable' => $visible_len >= 45,
            'prefix' => '',
            'suffix' => '',
        ]];
    }


    public function rest_ai_rewrite_page(WP_REST_Request $r) {
        $p = $r->get_json_params() ?: [];
        $page_id = absint($p['id'] ?? 0);
        $limit = isset($p['limit']) ? max(1, min(30, absint($p['limit']))) : 30;
        $apply = !empty($p['apply']);
        $post = get_post($page_id);
        if (!$post) return new WP_Error('summiteo_not_found', 'Page introuvable.', ['status'=>404]);
        $s = self::settings();
        if (!$this->has_ai_api_key($s)) {
            $provider = $this->selected_ai_provider($s);
            $label = $provider === 'claude' ? 'Claude' : 'OpenAI';
            return new WP_Error('ai_disabled', 'La clé API ' . $label . ' est absente.', ['status'=>403, 'provider'=>$provider]);
        }
        $respect_length = !empty($s['respect_text_length']) && $s['respect_text_length'] === '1';
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
                'length_control_enabled' => $respect_length,
                'applied' => false,
                'skipped_reason' => '',
            ];
            if ($apply) {
                if ($respect_length && !$length_check['ok']) {
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
        $respect_length = !empty($settings['respect_text_length']) && $settings['respect_text_length'] === '1';
        $is_classic = in_array(($block['widget_type'] ?? ''), ['classic-content','avia-content'], true);
        $max_attempts = ($respect_length && !$is_classic) ? 10 : 1;
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

            $text = $this->call_ai_text($prompt, $settings);
            if (is_wp_error($text)) return $text;
            $text = $this->normalise_rewrite_for_block($text, $block);
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
            if (!$respect_length) {
                return $this->clean_html($text);
            }

            $direction = ($check['new_length'] > $bounds['max']) ? 'raccourcir' : 'allonger';
            $delta = abs((int)$check['new_length'] - (int)$bounds['target']);
            $last_reason = 'Tentative ' . $attempt . ' non conforme. Le texte visible généré fait ' . $check['new_length'] . ' caractères visibles, hors balises HTML. Il faut ' . $direction . ' d\'environ ' . $delta . ' caractères visibles pour rester entre ' . $bounds['min'] . ' et ' . $bounds['max'] . ' caractères, cible idéale ' . $bounds['target'] . '. Ne compte pas les balises HTML. Ne rajoute pas de paragraphe. Ajuste uniquement la densité de la phrase : plus synthétique si trop long, plus précis si trop court.';
        }

        if ($respect_length && $best_text !== '' && !$is_classic) {
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
        $respect_length = !empty($settings['respect_text_length']) && $settings['respect_text_length'] === '1';
        $field = (string)($block['field'] ?? '');
        $heading_rule = $this->is_elementor_heading_field($block)
            ? "CHAMP TITRE ELEMENTOR : réponds avec un seul titre, sans balise <p>, sans <div>, sans wrapper, sans phrase d'introduction. Conserve uniquement les balises inline déjà utiles comme <br> ou <strong> si nécessaire.\n"
            : '';
        $length_rule = $respect_length
            ? "LONGUEUR IMPÉRATIVE : le texte réécrit doit avoir une longueur très proche du texte source pour ne pas casser la mise en page Elementor.\n" .
                "Texte source hors balises : {$old_len} caractères. Cible prioritaire : {$bounds['target']} caractères visibles. Fourchette acceptée : {$bounds['min']} à {$bounds['max']} caractères visibles.\n" .
                "La longueur se mesure uniquement sur le texte visible, sans compter les balises HTML. Avant de répondre, ajuste mentalement la longueur du texte final.\n" .
                "Si tu ajoutes un détail local, retire une précision ailleurs. Si le texte est trop court, ajoute seulement une précision utile, sans nouveau paragraphe.\n"
            : "LONGUEUR : aucune limite stricte de longueur n'est imposée. Priorise la qualité éditoriale, la clarté et le naturel, tout en restant cohérent avec la structure du bloc source.\n";
        $brief_section = $brief !== '' ? "Brief éditorial IA :\n" . $brief . "\n\n" : "Brief éditorial IA : aucun brief fourni. Ne suppose aucun secteur d'activité, aucune marque et aucune consigne métier spécifique.\n\n";
        return "Tu réécris {$content_label} pour un contenu WordPress.\n" .
            "Objectif : améliorer la clarté, le naturel et la qualité éditoriale sans inventer de contexte métier absent du texte source.\n" .
            $attribute_rule .
            $heading_rule .
            "Préserve les balises HTML utiles existantes comme <p>, <strong>, <br>. Ne renvoie que le HTML final du bloc, sans commentaire, sans markdown.\n" .
            "STRUCTURE STRICTE : conserve le même nombre de paragraphes et de retours ligne que le bloc source. Paragraphes source : {$profile['p_count']}. BR source : {$profile['br_count']}. Si le bloc source n'a pas de balise <p>, n'ajoute pas de balise <p>.\n" .
            "N'ajoute pas de deuxième paragraphe, pas de nouvelle commune, pas de quartier, pas d'exemple produit supplémentaire, sauf si c'est déjà présent dans le texte source.\n" .
            "Respecte l'apostrophe simple si possible. N'utilise aucun emoji ni tiret long.\n" .
            $length_rule .
            $strict . "\n" .
            $brief_section .
            "Type de widget : " . ($block['widget_type'] ?? '') . "\n" .
            "Champ : " . $field . "\n" .
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

        $text = $this->call_ai_text($prompt, $settings);
        if (is_wp_error($text)) return $text;
        return $this->normalise_rewrite_for_block($text, $block);
    }

    private function selected_ai_provider($settings) {
        $provider = sanitize_key((string)($settings['ai_provider'] ?? 'openai'));
        return in_array($provider, ['openai','claude'], true) ? $provider : 'openai';
    }

    private function has_ai_api_key($settings) {
        $provider = $this->selected_ai_provider($settings);
        if ($provider === 'claude') {
            return !empty($settings['claude_api_key']);
        }
        return !empty($settings['openai_api_key']);
    }

    private function call_ai_text($prompt, $settings, $args = []) {
        $provider = $this->selected_ai_provider($settings);
        if ($provider === 'claude') {
            return $this->call_claude_text($prompt, $settings, $args);
        }
        return $this->call_openai_text($prompt, $settings, $args);
    }

    private function call_openai_text($prompt, $settings, $args = []) {
        if (empty($settings['openai_api_key'])) {
            return new WP_Error('openai_disabled', 'La clé API OpenAI est absente.', ['status'=>403]);
        }
        $timeout = isset($args['timeout']) ? absint($args['timeout']) : 90;
        $temperature = isset($args['temperature']) ? (float)$args['temperature'] : 0.1;
        $max_tokens = isset($args['max_tokens']) ? absint($args['max_tokens']) : 6000;
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['openai_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $settings['openai_model'] ?: 'gpt-4.1-mini',
                'input' => $prompt,
                'temperature' => $temperature,
                'max_output_tokens' => $max_tokens,
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

    private function call_claude_text($prompt, $settings, $args = []) {
        if (empty($settings['claude_api_key'])) {
            return new WP_Error('claude_disabled', 'La clé API Claude est absente.', ['status'=>403]);
        }
        $timeout = isset($args['timeout']) ? absint($args['timeout']) : 90;
        $max_tokens = isset($args['max_tokens']) ? absint($args['max_tokens']) : 6000;
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => $timeout,
            'headers' => [
                'x-api-key' => $settings['claude_api_key'],
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $settings['claude_model'] ?: 'claude-opus-4-1-20250805',
                'max_tokens' => $max_tokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) return new WP_Error('claude_error', 'Erreur Claude : ' . $body, ['status'=>502]);
        $json = json_decode($body, true);
        $text = $this->extract_claude_text($json);
        if ($text === '') return new WP_Error('claude_empty', 'Réponse Claude vide ou illisible.', ['status'=>502]);
        return $text;
    }

    private function normalise_ai_html($text) {
        $text = preg_replace('/^```(?:html)?\s*/i', '', trim((string)$text));
        $text = preg_replace('/\s*```$/', '', $text);
        return $this->clean_html($text);
    }

    private function normalise_rewrite_for_block($text, $block) {
        $text = $this->normalise_ai_html($text);
        if ($this->is_elementor_heading_field($block)) {
            return $this->normalise_elementor_heading_text($text, (string)($block['text'] ?? ''));
        }
        return $text;
    }

    private function is_elementor_heading_field($block) {
        $field = (string)($block['field'] ?? '');
        $widget_type = (string)($block['widget_type'] ?? '');
        if (in_array($field, ['title','heading_title','title_text'], true)) {
            return !in_array($widget_type, ['text-editor','classic-content','avia-content'], true);
        }
        return false;
    }

    private function normalise_elementor_heading_text($text, $original_text = '') {
        $text = preg_replace('/^```(?:html)?\s*/i', '', trim((string)$text));
        $text = preg_replace('/\s*```$/', '', $text);
        $text = preg_replace('/<\/(?:p|div|h[1-6])>\s*<(?=p|div|h[1-6]\b)/i', '<br>', $text);
        $text = preg_replace('/<\/?(?:p|div|h[1-6])[^>]*>/i', '', $text);
        $allowed = [
            'br' => [],
            'strong' => [],
            'b' => [],
            'em' => [],
            'i' => [],
            'span' => ['class'=>true],
        ];
        $text = wp_kses($text, $allowed);
        $text = preg_replace('/(?:\s*<br\s*\/?>\s*){3,}/i', '<br><br>', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (stripos((string)$original_text, '<br') === false) {
            $text = preg_replace('/\s*<br\s*\/?>\s*/i', ' ', $text);
            $text = preg_replace('/\s+/', ' ', trim($text));
        }
        return $text;
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

    private function extract_claude_text($json) {
        if (!is_array($json)) return '';
        if (!empty($json['content']) && is_array($json['content'])) {
            $parts = [];
            foreach ($json['content'] as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    $parts[] = $content['text'];
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

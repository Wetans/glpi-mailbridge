/*
 * MailTech - comportamento das telas do plugin.
 * Carregado só nas telas do MailTech. Usa o jQuery que o GLPI já carrega e
 * nenhuma outra biblioteca. Todo texto vindo do servidor entra na página
 * como texto (nunca como HTML).
 */
(function ($) {
   'use strict';

   var root = document.getElementById('mailtech');
   if (!root) {
      return;
   }

   var cfg = {};
   try {
      cfg = JSON.parse(root.getAttribute('data-tm') || '{}');
   } catch (e) {
      cfg = {};
   }

   // ------------------------------------------------------------------
   // Avisos
   // ------------------------------------------------------------------

   function announce(message) {
      var live = document.getElementById('tm-live');
      if (live) {
         live.textContent = '';
         window.setTimeout(function () {
            live.textContent = message;
         }, 50);
      }
   }

   function toast(message, ok) {
      if (!message) {
         return;
      }
      announce(message);
      /* global glpi_toast_info, glpi_toast_error */
      if (ok && typeof glpi_toast_info === 'function') {
         glpi_toast_info(escapeHtml(message));
      } else if (!ok && typeof glpi_toast_error === 'function') {
         glpi_toast_error(escapeHtml(message));
      } else {
         // Sem os avisos flutuantes do GLPI: mostra a mensagem no topo da tela.
         var flash = document.getElementById('tm-flash');
         if (flash) {
            flash.className = 'alert alert-' + (ok ? 'success' : 'danger');
            flash.textContent = message;
            flash.hidden = false;
         }
      }
   }

   function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
   }

   function el(tag, className, text) {
      var node = document.createElement(tag);
      if (className) {
         node.className = className;
      }
      if (text !== undefined && text !== null) {
         node.textContent = text;
      }
      return node;
   }

   // ------------------------------------------------------------------
   // Validação ao sair do campo (o servidor valida de novo ao salvar)
   // ------------------------------------------------------------------

   var HOST = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i;
   var IPV4 = /^(\d{1,3}\.){3}\d{1,3}$/;
   var LOCAL = /^[^\s@"(),:;<>\[\]\\]+$/;

   function isHost(value) {
      return value.length <= 253 && (HOST.test(value) || IPV4.test(value) || (value.indexOf(':') !== -1 && /^[0-9a-f:.]+$/i.test(value)));
   }

   function isEmail(value) {
      var at = value.lastIndexOf('@');
      if (at < 1 || value.length > 254 || /\s/.test(value)) {
         return false;
      }
      return LOCAL.test(value.slice(0, at)) && isHost(value.slice(at + 1));
   }

   function fieldError(input) {
      var value = $.trim(input.value);
      var rule = input.getAttribute('data-tm-validate') || '';
      var message = input.getAttribute('data-tm-msg') || cfg.required || '';

      if (value === '') {
         if (input.hasAttribute('data-tm-required')) {
            return input.getAttribute('data-tm-msg-required') || cfg.required || message;
         }
         return '';
      }
      if (rule === 'email' && !isEmail(value)) {
         return message;
      }
      if (rule === 'host' && !isHost(value)) {
         return message;
      }
      if (rule === 'int') {
         var min = parseInt(input.getAttribute('min'), 10);
         var max = parseInt(input.getAttribute('max'), 10);
         var number = parseInt(value, 10);
         if (!/^\d+$/.test(value) || (!isNaN(min) && number < min) || (!isNaN(max) && number > max)) {
            return message;
         }
      }
      return '';
   }

   function showFieldError(input, message) {
      var box = document.getElementById(input.id + '_err');
      if (message) {
         input.classList.add('is-invalid');
         input.setAttribute('aria-invalid', 'true');
      } else {
         input.classList.remove('is-invalid');
         input.removeAttribute('aria-invalid');
      }
      if (box) {
         box.textContent = message;
      }
   }

   function isShown(node) {
      return !!(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
   }

   /** Valida os campos visíveis de um formulário; devolve o primeiro inválido. */
   function validateForm(form) {
      var first = null;
      $(form).find('[data-tm-validate], [data-tm-required]').not('.tm-skip-validate *').each(function () {
         // Campo dentro de "Opções avançadas" fechadas: abre para mostrar o erro.
         var message = (isShown(this) || $(this).closest('details').length) && !$(this).closest('[hidden]').length
            ? fieldError(this)
            : '';
         showFieldError(this, message);
         if (message && !first) {
            first = this;
         }
      });
      if (first) {
         $(first).parents('details').prop('open', true);
         first.focus();
         announce(document.getElementById(first.id + '_err').textContent);
      }
      return first;
   }

   $(root).on('blur', '[data-tm-validate], [data-tm-required]', function () {
      showFieldError(this, fieldError(this));
   });

   $(root).on('input', '.is-invalid', function () {
      if (!fieldError(this)) {
         showFieldError(this, '');
      }
   });

   $(root).on('submit', 'form[novalidate]:not(.tm-ajax)', function (event) {
      if (validateForm(this)) {
         event.preventDefault();
         event.stopImmediatePropagation();
         window.glpiUnsavedFormChanges = true;
      }
   });

   // ------------------------------------------------------------------
   // Mostrar/ocultar blocos conforme uma escolha (data-tm-show="campo=valor")
   // ------------------------------------------------------------------

   function refreshShow() {
      $(root).find('[data-tm-show]').each(function () {
         var parts = this.getAttribute('data-tm-show').split('=');
         var control = root.querySelector('[name="' + parts[0] + '"]:not([type=hidden])');
         if (control) {
            this.hidden = control.value !== parts[1];
         }
      });
   }

   $(root).on('change', 'select, input[type=radio]', refreshShow);
   refreshShow();

   // Sugere a porta usual quando a segurança muda (só se a porta atual for uma das usuais).
   $(root).on('change', '#tm_security', function () {
      var port = document.getElementById('tm_smtp_port');
      var usual = {none: '25', ssl: '465', tls: '587'};
      if (port && (port.value === '' || port.value === '25' || port.value === '465' || port.value === '587')) {
         port.value = usual[this.value] || port.value;
         showFieldError(port, '');
      }
   });

   // ------------------------------------------------------------------
   // Confirmações de ações perigosas
   // ------------------------------------------------------------------

   $(root).on('change', 'input[data-tm-confirm-off]:not(.tm-toggle)', function () {
      if (!this.checked && !window.confirm(this.getAttribute('data-tm-confirm-off'))) {
         this.checked = true;
      }
   });

   $(root).on('click', '[data-tm-confirm]', function (event) {
      var form = this.form;
      if (this.getAttribute('data-tm-needs-selection') && form && !$(form).find('input[name="ids[]"]:checked').length) {
         event.preventDefault();
         toast(cfg.select_some, false);
         return;
      }
      if (!window.confirm(this.getAttribute('data-tm-confirm'))) {
         event.preventDefault();
         event.stopImmediatePropagation();
      }
   });

   // Aviso de alterações não salvas (o GLPI já pergunta ao sair da página).
   var dirty = false;
   $(root).on('input change', 'form[data-track-changes] :input', function () {
      dirty = true;
      $('#tm-dirty-note').prop('hidden', false);
   });

   // ------------------------------------------------------------------
   // Chamadas sem recarregar a página
   // ------------------------------------------------------------------

   function post(data) {
      return $.ajax({url: cfg.ajax, method: 'POST', data: data, dataType: 'json'});
   }

   function failureMessage(xhr) {
      if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
         return xhr.responseJSON.message;
      }
      return cfg.network_error;
   }

   /** Monta o resultado de um teste (mesma estrutura de templates/_test_result.html.twig). */
   function renderResult(target, json) {
      var data = json.data || {};
      var box = el('div', 'alert mb-0 alert-' + (json.ok ? 'success' : 'danger'));

      if (json.message) {
         box.appendChild(el('p', 'fw-bold mb-2', json.message));
      }
      if (data.steps && data.steps.length) {
         var list = el('ol', 'tm-steps');
         $.each(data.steps, function (i, step) {
            var item = el('li');
            var icons = {ok: 'ti ti-circle-check text-success', fail: 'ti ti-circle-x text-danger', skip: 'ti ti-minus'};
            var words = {ok: cfg.ok, fail: cfg.failed, skip: cfg.skipped};
            var icon = el('i', icons[step.status] || icons.skip);
            icon.setAttribute('aria-hidden', 'true');
            item.appendChild(icon);
            item.appendChild(el('span', 'visually-hidden', (words[step.status] || '') + ':'));
            var label = el('span', '', step.label);
            if (step.detail) {
                label.appendChild(document.createTextNode(' '));
                label.appendChild(el('span', 'tm-soft', step.detail));
            }
            item.appendChild(label);
            list.appendChild(item);
         });
         box.appendChild(list);
      }
      if (data.advice && data.advice.length) {
         box.appendChild(el('p', 'fw-bold mt-2 mb-1', cfg.what_to_try));
         var tips = el('ul', 'mb-0');
         $.each(data.advice, function (i, tip) {
            tips.appendChild(el('li', '', tip));
         });
         box.appendChild(tips);
      }
      if (data.technical) {
         var details = el('details', 'mt-2');
         details.appendChild(el('summary', '', cfg.technical));
         details.appendChild(el('code', 'tm-break', data.technical));
         box.appendChild(details);
      }
      if (json.warning) {
         box.appendChild(el('p', 'mt-2 mb-0', json.warning));
      }

      target.textContent = '';
      target.appendChild(box);
      announce(json.message || '');
   }

   function busy(button, on) {
      if (!button) {
         return;
      }
      button.disabled = on;
      button.classList.toggle('tm-busy', on);
      if (on) {
         button.setAttribute('aria-busy', 'true');
      } else {
         button.removeAttribute('aria-busy');
      }
   }

   function runInto(target, button, data) {
      busy(button, true);
      if (target) {
         target.textContent = '';
         target.appendChild(el('p', 'tm-soft mb-0', cfg.working));
      }
      return post(data).done(function (json) {
         if (target) {
            renderResult(target, json);
         } else {
            toast(json.message, json.ok);
         }
      }).fail(function (xhr) {
         var json = {ok: false, message: failureMessage(xhr), data: (xhr.responseJSON && xhr.responseJSON.data) || {}};
         if (target) {
            renderResult(target, json);
         } else {
            toast(json.message, false);
         }
      }).always(function () {
         busy(button, false);
      });
   }

   // Formulários pequenos (testes): enviam sem recarregar e mostram o resultado ao lado.
   $(root).on('submit', 'form.tm-ajax', function (event) {
      event.preventDefault();
      if (validateForm(this)) {
         return;
      }
      var target = document.querySelector(this.getAttribute('data-tm-result') || '');
      // Os testes usam só o que está salvo: com alterações pendentes o resultado enganaria.
      if (dirty && this.hasAttribute('data-tm-saved-only')) {
         var unsaved = {ok: false, message: cfg.unsaved_title, data: {advice: [cfg.unsaved_tip]}};
         if (target) {
            renderResult(target, unsaved);
         } else {
            toast(unsaved.message, false);
         }
         return;
      }
      runInto(target, this.querySelector('button[type=submit]'), $(this).serialize());
   });

   // Chaves liga/desliga que gravam na hora (regras, avisos nativos, avisos do MailTech).
   $(root).on('change', 'input.tm-toggle', function () {
      var input = this;
      var on = input.checked;
      var warning = input.getAttribute('data-tm-confirm-off');
      if (!on && warning && !window.confirm(warning)) {
         input.checked = true;
         return;
      }
      input.disabled = true;
      post({action: input.getAttribute('data-action'), id: input.getAttribute('data-id') || 0, on: on ? 1 : 0})
         .done(function (json) {
            if (!json.ok) {
               input.checked = !on;
            }
            var state = $(input).closest('[data-tm-row]').find('[data-tm-state]');
            if (json.ok && state.length) {
               state.text(on ? state.attr('data-on') : state.attr('data-off'));
            }
            toast(json.message, json.ok);
         })
         .fail(function (xhr) {
            input.checked = !on;
            toast(failureMessage(xhr), false);
         })
         .always(function () {
            input.disabled = false;
            input.focus();
         });
   });

   // ------------------------------------------------------------------
   // Fila: marcar todos
   // ------------------------------------------------------------------

   $(root).on('change', '#tm-check-all', function () {
      $(root).find('input[name="ids[]"]').prop('checked', this.checked);
   });

   // ------------------------------------------------------------------
   // Editor de modelos
   // ------------------------------------------------------------------

   var editor = document.getElementById('tm-template-form');
   if (editor) {
      var lastField = null;
      var previewTimer = null;
      var frame = document.getElementById('tm-preview-frame');

      var richBody = function () {
         return (window.tinymce && window.tinymce.get('tm_body')) || null;
      };

      var syncEditor = function () {
         if (window.tinymce) {
            window.tinymce.triggerSave();
         }
      };

      var preview = function () {
         syncEditor();
         var status = document.getElementById('tm-preview-status');
         if (status) {
            status.textContent = cfg.preview_wait;
         }
         post({
            action: 'preview_template',
            id: editor.elements.id.value,
            lang: editor.elements.lang.value,
            subject: editor.elements.subject.value,
            body: editor.elements.body.value,
            body_text: editor.elements.body_text.value
         }).done(function (json) {
            if (!json.ok) {
               if (status) {
                  status.textContent = json.message || '';
               }
               return;
            }
            document.getElementById('tm-preview-subject').textContent = json.data.subject;
            document.getElementById('tm-preview-text').textContent = json.data.text;
            // Moldura isolada: scripts e formulários do conteúdo não rodam.
            frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank">'
               + '<body style="font-family:sans-serif;font-size:14px;margin:12px;color:#222;background:#fff">'
               + json.data.html + '</body>';
            var unknown = document.getElementById('tm-preview-unknown');
            if (unknown) {
               var names = json.data.unknown || [];
               unknown.hidden = !names.length;
               unknown.querySelector('span').textContent = names.length ? '##' + names.join('##, ##') + '##' : '';
            }
            if (status) {
               status.textContent = '';
            }
         }).fail(function (xhr) {
            if (status) {
               status.textContent = failureMessage(xhr);
            }
         });
      };

      var schedulePreview = function () {
         window.clearTimeout(previewTimer);
         previewTimer = window.setTimeout(preview, 700);
      };

      // Editor de texto formatado: usa o TinyMCE que já vem com o GLPI. Os endereços
      // dos links não são convertidos, para "##ticket.url##" continuar intacto.
      var area = document.getElementById('tm_body');
      var rich_options = {};
      try {
         rich_options = JSON.parse(area.getAttribute('data-tm-rich') || '{}');
      } catch (e) {
         rich_options = {};
      }
      var rich_tries = 0;
      var rich_wait = window.setInterval(function () {
         rich_tries++;
         if (!window.tinyMCE) {
            if (rich_tries > 60) {
               window.clearInterval(rich_wait); // sem o editor, fica a caixa de texto simples (HTML)
            }
            return;
         }
         window.clearInterval(rich_wait);
         var dark = $.trim($('html').css('--is-dark') || '') === 'true';
         var config = {
            license_key: 'gpl',
            selector: '#tm_body',
            branding: false,
            menubar: false,
            elementpath: false,
            text_patterns: false,
            plugins: ['autoresize', 'code', 'fullscreen', 'link', 'lists', 'table'],
            toolbar: 'bold italic | bullist numlist | link table | undo redo | code fullscreen',
            convert_urls: false,
            entity_encoding: 'raw',
            invalid_elements: 'applet,canvas,embed,form,object,iframe,script,style',
            link_default_target: '_blank',
            min_height: 280,
            max_height: 560,
            browser_spellcheck: true,
            skin_url: rich_options.skin + (dark ? '/oxide-dark' : '/oxide'),
            content_style: 'body{font-family:sans-serif;font-size:14px;color:#222;background:#fff}',
            cache_suffix: rich_options.cache || '',
            setup: function (instance) {
               instance.on('change input', function () {
                  window.glpiUnsavedFormChanges = true;
                  $('#tm-dirty-note').prop('hidden', false);
               });
            }
         };
         if (rich_options.lang) {
            config.language = rich_options.lang;
            config.language_url = rich_options.lang_url;
         }
         window.tinyMCE.init(config);
      }, 100);

      $(editor).on('focusin', '#tm_subject, #tm_body, #tm_body_text', function () {
         lastField = this;
      });
      $(editor).on('input', '#tm_subject, #tm_body, #tm_body_text', schedulePreview);

      // O editor de texto formatado do GLPI (TinyMCE) é criado depois: espera por ele.
      var hook = window.setInterval(function () {
         var rich = richBody();
         if (rich) {
            window.clearInterval(hook);
            rich.on('focus', function () {
               lastField = 'rich';
            });
            rich.on('input change undo redo SetContent', schedulePreview);
         }
      }, 300);
      window.setTimeout(function () {
         window.clearInterval(hook);
      }, 10000);

      // Inserir variável com um clique, onde o cursor estava.
      $(editor).on('click', '[data-tm-var]', function () {
         var token = '##' + this.getAttribute('data-tm-var') + '##';
         var rich = richBody();
         if ((lastField === 'rich' || lastField === null || lastField.id === 'tm_body') && rich) {
            rich.insertContent(token);
            rich.focus();
         } else {
            var field = lastField && lastField.id ? lastField : document.getElementById('tm_body');
            var start = field.selectionStart === undefined ? field.value.length : field.selectionStart;
            var end = field.selectionEnd === undefined ? field.value.length : field.selectionEnd;
            field.value = field.value.slice(0, start) + token + field.value.slice(end);
            field.focus();
            field.selectionStart = field.selectionEnd = start + token.length;
         }
         window.glpiUnsavedFormChanges = true;
         $('#tm-dirty-note').prop('hidden', false);
         announce(token);
         schedulePreview();
      });

      // Restaurar o texto padrão (só passa a valer depois de Salvar).
      $(editor).on('click', '#tm-restore', function () {
         if (!window.confirm(this.getAttribute('data-tm-question'))) {
            return;
         }
         var defaults = JSON.parse(editor.getAttribute('data-tm-default') || '{}');
         editor.elements.subject.value = defaults.subject || '';
         editor.elements.body_text.value = '';
         var rich = richBody();
         if (rich) {
            rich.setContent(defaults.body || '');
         }
         editor.elements.body.value = defaults.body || '';
         window.glpiUnsavedFormChanges = true;
         $('#tm-dirty-note').prop('hidden', false);
         toast(this.getAttribute('data-tm-done'), true);
         preview();
      });

      $(root).on('click', '#tm-preview-now', preview);

      // Enviar teste do modelo com o que está no editor (mesmo sem salvar).
      $(editor).on('click', '#tm-test-template', function (event) {
         event.preventDefault();
         var email = document.getElementById('tm_test_email');
         var message = $.trim(email.value) === '' ? email.getAttribute('data-tm-msg') : fieldError(email);
         showFieldError(email, message);
         if (message) {
            email.focus();
            return;
         }
         syncEditor();
         var data = $(editor).serializeArray().filter(function (pair) {
            return pair.name !== 'action';
         });
         data.push({name: 'action', value: 'test_template'});
         runInto(document.getElementById('tm-test-result'), this, $.param(data));
      });

      $(editor).on('submit', syncEditor);
      preview();
   }

   // ------------------------------------------------------------------
   // Levar o foco para o ponto pedido (ex.: "Enviar e-mail de teste" da Visão geral)
   // ------------------------------------------------------------------

   var focus = root.querySelector('[data-tm-focus]');
   if (focus) {
      focus.scrollIntoView({block: 'center'});
      var first = focus.querySelector('input:not([type=hidden]), button, select, textarea, a[href], summary');
      if (first) {
         first.focus({preventScroll: true});
      }
   }
}(window.jQuery));

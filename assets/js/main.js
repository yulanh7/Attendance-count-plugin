/* =========================================================
 * Attendance Plugin - main.js (完整合并版)
 * =======================================================*/
jQuery(function ($) {
  const AP = (window.AP = window.AP || {});
  const S = {
    // 原有选择器
    tableBox: "#filter-table-response",
    loaderBox: "#loader-box",
    form: "#es_attendance_form",
    modal: "#attendance-info-modal",
    modalContent: "#attendance-info-modal-content",
    // 新增三合一表单选择器
    container: ".es-attendance-container",
    quickForm: "#es_quick_attendance_form",
    firstTimeForm: "#es_first_time_form",
    profileForm: "#es_profile_form",
  };

  const storage = {
    get(k, d = null) { try { const raw = localStorage.getItem(k); return raw ? JSON.parse(raw) : d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { } },
    del(k) { try { localStorage.removeItem(k); } catch { } },
  };

  const api = {
    post(action, payload = {}) {
      return $.ajax({ url: esAjax.ajaxurl, type: "POST", dataType: "json", data: { action, nonce: esAjax.nonce, ...payload } })
        .fail((xhr) => { const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || xhr.responseText || "Network error"; console.error("AJAX error:", msg); });
    },
    postRaw(action, payload = {}) {
      return $.ajax({ url: esAjax.ajaxurl, type: "POST", data: { action, nonce: esAjax.nonce, ...payload } })
        .fail((xhr) => { console.error("AJAX raw error:", xhr && xhr.responseText); });
    },
  };

  function feScrollToContainerTop($c, offset = 10) {
    const top = $c.offset() ? $c.offset().top : 0;
    $("html, body").stop(true).animate({ scrollTop: Math.max(top - offset, 0) }, 300);
  }

  function showLoader(show) {
    const $b = $(S.loaderBox);
    if (!$b.length) return;
    show ? $b.show() : $b.hide();
  }

  function displayMessage($after, message, color) {
    $(".es-message").remove();
    $("<div>", { class: "es-message", text: message, css: { background: color, color: "#fff", padding: "8px", "margin-top": "10px", "border-radius": "5px" } }).insertAfter($after);
  }

  function normalizePhone(cc, num) {
    let n = (num || "").replace(/[\s\-()]/g, "");
    if (cc === "+61" && n.charAt(0) === "0") n = n.slice(1);
    return (cc || "") + n;
  }

  function getSubmitButton($form) {
    const $active = $(document.activeElement);
    if ($active.is('button[type=submit], input[type=submit]')) return $active;
    return $form.find('button[type=submit], input[type=submit]').first();
  }

  function setSubmitting($btn, on) {
    if (!$btn || !$btn.length) return;

    // 根据所在容器决定提示条插入位置
    function ensureHintEl() {
      const $row = $btn.closest('.profile-action-row');
      if ($row.length) {
        // 提示放在整个按钮行的后面 => 强制下一行显示
        let $h = $row.next('.submit-hint');
        if (!$h.length) {
          $h = $('<div class="submit-hint" role="status" aria-live="polite"/>').insertAfter($row);
        }
        return $h;
      } else {
        let $h = $btn.next('.submit-hint');
        if (!$h.length) {
          $h = $('<div class="submit-hint" role="status" aria-live="polite"/>').insertAfter($btn);
        }
        return $h;
      }
    }

    const $hint = ensureHintEl();

    // 记录初始文案
    if ($btn.data('init-label') === undefined) {
      const initLabel = $btn.is('input') ? $btn.val() : $btn.html();
      $btn.data('init-label', initLabel);
    }

    if (on) {
      if ($btn.data('submitting') === true) return;
      $btn.data('submitting', true);

      if ($btn.data('orig-label') === undefined) {
        let original = $btn.is('input') ? $btn.val() : $btn.html();
        if (/提交中/.test(original)) original = $btn.data('init-label');
        $btn.data('orig-label', original);
      }

      if ($btn.is('input')) $btn.val('提交中…'); else $btn.text('提交中…');
      $btn.prop('disabled', true).attr('aria-busy', 'true');
      $hint.text('在处理中，请勿关闭窗口').show();

    } else {
      if ($btn.data('submitting') !== true) return;
      $btn.data('submitting', false);

      const original = $btn.data('orig-label');
      const fallback = $btn.data('init-label');
      const textToRestore = (original !== undefined) ? original : fallback;

      if (textToRestore !== undefined) {
        if ($btn.is('input')) $btn.val(textToRestore); else $btn.html(textToRestore);
      }

      $btn.removeData('orig-label');
      $btn.prop('disabled', false).removeAttr('aria-busy');
      $hint.text('').hide();
    }
  }

  function ensureProfileActionButtons($form) {
    // 找到 submit
    const $submit = $form.find('button[type=submit], input[type=submit]').last();
    if (!$submit.length) return;

    // 创建/查找按钮行
    let $row = $form.find('.profile-action-row');
    if (!$row.length) {
      $row = $('<div class="profile-action-row"></div>');
      $submit.after($row);
      $row.append($submit); // 把 submit 放进行内
    } else {
      if (!$row.find($submit).length) $row.prepend($submit);
    }

    // 取消
    let $cancel = $row.find('#profile-cancel-edit');
    if (!$cancel.length) {
      $cancel = $('<button type="button" id="profile-cancel-edit" class="switch-btn" style="margin-left:8px;">取消</button>');
      $row.append($cancel);
    }

    // 修改其他号码
    let $editOther = $row.find('#profile-edit-other');
    if (!$editOther.length) {
      $editOther = $('<button type="button" id="profile-edit-other" class="switch-btn" style="margin-left:8px; display:none;">修改其他号码</button>');
      $row.append($editOther);
    }
  }



  function shouldAdoptCurrentPhone(resp, msg) {
    if (resp && resp.success) return true;
    const s = String(msg || '').toLowerCase();
    return /已经签到|已签到|重复|already\s*(checked\s*in|signed)|duplicate/.test(s);
  }

  /* ========== 原有前台表单（保持不变） ========== */
  function initForm() {
    const $form = $(S.form);
    if (!$form.length) return;

    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form.find("select[name=es_phone_country_code]").val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("select[name=es_fellowship]").val(saved.es_fellowship || "");

    // 原有手机号一致性与提示逻辑
    const $cc = $form.find("select[name=es_phone_country_code]");
    const $num = $form.find("input[name=es_phone_number]");

    let savedPhone = normalizePhone(saved.es_phone_country_code || "", saved.es_phone_number || "");
    let hintShown = false;

    function showPhoneHintOnce() {
      if (hintShown) return;
      if (!savedPhone) return
      hintShown = true;

      const $box = $form.find(".phone-box");
      if ($box.next(".phone-hint").length === 0) {
        const text = "提示：除非需要为他人签到，否则请勿更换电话号码（用于唯一身份识别）";
        $("<div/>", {
          class: "phone-hint",
          text,
          css: { color: "#f99522ff", "font-size": "16px", "margin-top": "4px", "margin-bottom": "6px" }
        }).insertAfter($box);
      }
    }

    function clearPhoneHint() {
      const $h = $form.find('.phone-hint');
      if ($h.length) $h.text('');
    }

    $cc.on("focus", showPhoneHintOnce);
    $num.on("focus", showPhoneHintOnce);

    $form.off("submit").off("submit.ap").on("submit.ap", function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      e.stopPropagation();
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const curPhone = normalizePhone($cc.val(), $num.val());

      function doSubmit() {
        setSubmitting($submit, true);
        const formData = {
          es_first_name: $form.find("input[name=es_first_name]").val(),
          es_last_name: $form.find("input[name=es_last_name]").val(),
          es_email: $form.find("input[name=es_email]").val(),
          es_phone_country_code: $cc.val(),
          es_phone_number: $num.val(),
          es_fellowship: $form.find("select[name=es_fellowship]").val(),
        };
        storage.set("es_attendance_form_data", formData);
        api.post("es_handle_attendance", formData)
          .done((resp) => {
            $(".es-message").remove();
            const ok = !!(resp && resp.success);
            const msg = (resp && resp.data && resp.data.message) || (ok ? "Success" : "Error");
            displayMessage($form, msg, ok ? "green" : "red");
            alert(msg);
            if (shouldAdoptCurrentPhone(resp, msg)) {
              savedPhone = normalizePhone($cc.val(), $num.val());
              const formData = {
                es_first_name: $form.find("input[name=es_first_name]").val(),
                es_last_name: $form.find("input[name=es_last_name]").val(),
                es_email: $form.find("input[name=es_email]").val(),
                es_phone_country_code: $cc.val(),
                es_phone_number: $num.val(),
                es_fellowship: $form.find("select[name=es_fellowship]").val(),
              };
              Object.assign(saved, formData);
              storage.set("es_attendance_form_data", formData);

            }
          })
          .always(() => {
            hintShown = false;
            clearPhoneHint();
            setSubmitting($submit, false);
          });
      }

      // 情况 A：本机没有保存过号码
      if (!savedPhone) {
        api.post("ap_check_phone_exists", {
          cc: $cc.val(),
          num: $num.val()
        }).done(function (resp) {
          const exists = !!(resp && resp.success && resp.data && resp.data.exists);
          if (!exists) {
            const ok = window.confirm(
              "确认手机号：系统将以此新号码作为你以后签到的身份识别。\n请确认无误后提交。\n确定提交吗？"
            );
            if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
          }
          doSubmit();
        }).fail(function () {
          const ok = window.confirm(
            "无法确认该号码是否已有记录。\n如这是新号码，将作为今后签到的身份识别。\n确定提交吗？"
          );
          if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
          doSubmit();
        });
        return;
      }

      // 情况 B：本机有保存号码
      if (curPhone && curPhone !== savedPhone) {
        const ok = window.confirm(
          "检测到你更改了电话号码。\n除非在帮他人签到，否则请不要更改号码。\n确定要用新号码提交吗？"
        );
        if (!ok) { setTimeout(() => { $num.focus(); $num.select && $num.select(); }, 0); return; }
      }

      doSubmit();
    });

    $form.on("focus", "input,select", function () { $(".es-message").remove(); });

    $("#last_date_filter").datepicker({ dateFormat: "dd/mm/yy", changeYear: true, changeMonth: true, showButtonPanel: true, yearRange: "c-100:c+0" });
  }

  /* ========== 新增：三合一表单逻辑 ========== */
  function switchToForm(targetForm) {
    $(S.quickForm + ", " + S.firstTimeForm + ", " + S.profileForm).hide();
    $(targetForm).show();
    $(".es-message").remove();
  }

  function loadSavedData($form) {
    const saved = storage.get("es_attendance_form_data", {});
    $form.find("input[name=es_first_name]").val(saved.es_first_name || "");
    $form.find("input[name=es_last_name]").val(saved.es_last_name || "");
    $form.find("input[name=es_email]").val(saved.es_email || "");
    $form.find("select[name=es_phone_country_code]").val(saved.es_phone_country_code || "+61");
    $form.find("input[name=es_phone_number]").val(saved.es_phone_number || "");
    $form.find("select[name=es_fellowship]").val(saved.es_fellowship || "");
  }

  function getFormData($form) {
    return {
      es_first_name: $form.find("input[name=es_first_name]").val() || "",
      es_last_name: $form.find("input[name=es_last_name]").val() || "",
      es_email: $form.find("input[name=es_email]").val() || "",
      es_phone_country_code: $form.find("select[name=es_phone_country_code]").val() || "+61",
      es_phone_number: $form.find("input[name=es_phone_number]").val() || "",
      es_fellowship: $form.find("select[name=es_fellowship]").val() || "",
    };
  }

  function resetProfileForm() {
    const $form = $(S.profileForm);
    $form.find("#profile-user-info").hide();
    $form.find("#profile-check-section").show();
    $form.find("input[name=es_first_name]").val("");
    $form.find("input[name=es_last_name]").val("");
    $form.find("input[name=es_email]").val("");
    $form.find("select[name=es_fellowship]").val("");
    $form.find("#profile-cancel-edit").hide();
    $form.find("#profile-edit-other").hide();
    $(".es-message").remove();
  }




  function prefillPhoneFromStorage($form) {
    const saved = storage.get("es_attendance_form_data", {}) || {};
    const cc = saved.es_phone_country_code || "+61";
    const num = saved.es_phone_number || "";
    $form.find("select[name=es_phone_country_code]").val(cc);
    $form.find("input[name=es_phone_number]").val(num);
  }


  function syncQuickFormPhone(cc, num) {
    const saved = storage.get("es_attendance_form_data", {}) || {};
    const updated = { ...saved, es_phone_country_code: cc || "+61", es_phone_number: num || "" };
    storage.set("es_attendance_form_data", updated);
    const $quick = $(S.quickForm);
    if ($quick.length) {
      $quick.find("select[name=es_phone_country_code]").val(updated.es_phone_country_code || "+61");
      $quick.find("input[name=es_phone_number]").val(updated.es_phone_number || "");
    }
  }


  function resetFirstTimeForm() {
    const $form = $(S.firstTimeForm);
    $form.find("input[name=es_first_name]").val("");
    $form.find("input[name=es_last_name]").val("");
    $form.find("input[name=es_email]").val("");
    $form.find("select[name=es_phone_country_code]").val("+61"); // 如需默认国家码
    $form.find("input[name=es_phone_number]").val("");
    $form.find("select[name=es_fellowship]").val("");
    $(".es-message").remove();
  }


  function checkPhoneAndLoadProfile(phone, $form) {
    const $checkBtn = $("#check-profile-phone");
    const originalText = $checkBtn.text();

    $checkBtn.prop("disabled", true).text("查找中...");

    api.post("ap_check_phone_exists", {
      cc: $form.find("select[name=es_phone_country_code]").val(),
      num: $form.find("input[name=es_phone_number]").val()
    })
      .done((resp) => {
        const exists = !!(resp && resp.success && resp.data && resp.data.exists);

        if (!exists) {
          const go = window.confirm(
            "该电话号码未注册，请完整填写信息进行首次登记。\n\n点击【确定】前往首次登记，点击【取消】留在当前页面。"
          );
          if (go) {
            // 切到首次登记前就把按钮复原
            $checkBtn.prop("disabled", false).text(originalText);
            resetFirstTimeForm && resetFirstTimeForm();
            switchToForm(S.firstTimeForm);
          } else {
            $checkBtn.prop("disabled", false).text(originalText);
            setTimeout(() => {
              const $numInput = $form.find("input[name=es_phone_number]");
              $numInput.focus();
              $numInput.select && $numInput.select();
            }, 0);
          }
          return;
        }

        // ★ 号码存在：进入“加载资料”第二阶段
        //   等 loadUserProfileForEdit 完成后，再把按钮从“查找中...”复原
        loadUserProfileForEdit(phone, $form)
          .always(() => {
            $checkBtn.prop("disabled", false).text(originalText);
          });
      })
      .fail(() => {
        displayMessage($form, "查找失败，请稍后再试", "red");
        $checkBtn.prop("disabled", false).text(originalText);
      });

  }

  function loadUserProfileForEdit(phone, $form) {
    const $info = $form.find("#profile-user-info");
    const $check = $form.find("#profile-check-section");

    // 查找阶段：保持在“查找”区，不切换、不显示表格
    // 返回 jqXHR 让上层等待完成后再复原按钮
    return api.post("ap_get_user_profile", { phone: phone })
      .done((resp) => {
        if (resp && resp.success && resp.data) {
          const data = resp.data;

          // 填入资料
          $form.find("input[name=es_first_name]").val(data.first_name || "");
          $form.find("input[name=es_last_name]").val(data.last_name || "");
          $form.find("input[name=es_email]").val(data.email || "");
          $form.find("select[name=es_fellowship]").val(data.fellowship || "");

          // 查找成功后再切换到“更新资料”区
          $check.hide();
          $info.show();
          ensureProfileActionButtons($form);

          // 查找完成时：只显示“取消”
          $form.find("#profile-cancel-edit").show();
          $form.find("#profile-edit-other").hide();

        } else {
          displayMessage($form, "加载资料失败", "red");
        }
      })
      .fail(() => {
        displayMessage($form, "加载资料失败，请稍后再试", "red");
      });
  }



  function doQuickAttendance(phone, $form, $submit) {
    setSubmitting($submit, true);

    api.post("es_quick_attendance", { phone: phone })
      .done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        const msg = (resp && resp.data && resp.data.message) || (ok ? "签到成功" : "签到失败");
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doQuickAttendanceFromFirstTime(phone, $form, $submit) {
    setSubmitting($submit, true);

    api.post("es_quick_attendance", { phone: phone })
      .done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        let msg = "";

        if (ok) {
          msg = (resp && resp.data && resp.data.message) || "签到成功";
          // 如果服务端返回的消息包含用户姓名，直接使用
          if (msg.includes("欢迎")) {
            msg = msg; // 保持原样，如"签到成功！欢迎 张三 李四"
          } else {
            msg = "您已在系统中登记，签到成功！";
          }
        } else {
          // 检查是否是重复签到的情况
          const originalMsg = (resp && resp.data && resp.data.message) || "";
          if (originalMsg.includes("已经签到") || originalMsg.includes("重复签到")) {
            msg = "您今天已经签到过了，请勿重复签到！";
          } else {
            msg = "签到失败，请稍后再试";
          }
        }
        if (ok) {
          const cc2 = $form.find("select[name=es_phone_country_code]").val();
          const num2 = $form.find("input[name=es_phone_number]").val();
          syncQuickFormPhone(cc2, num2);
        }
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doFullAttendance($form, $submit) {
    setSubmitting($submit, true);
    const formData = getFormData($form);

    api.post("es_handle_attendance", formData)
      .done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        const msg = (resp && resp.data && resp.data.message) || (ok ? "登记成功" : "登记失败");
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);

        if (shouldAdoptCurrentPhone(resp, msg)) {
          storage.set("es_attendance_form_data", formData);
          syncQuickFormPhone(formData.es_phone_country_code, formData.es_phone_number);

        }
      })
      .always(() => {
        setSubmitting($submit, false);
      });
  }

  function doUpdateProfile($form, $submit) {
    // 提交期间需要禁用的按钮
    const $alsoDisable = $form.find('#profile-cancel-edit, #profile-edit-other, #profile-to-quick, #profile-to-first');

    setSubmitting($submit, true);
    $alsoDisable.prop('disabled', true).attr('aria-disabled', 'true');

    const formData = getFormData($form);

    api.post("ap_update_profile", formData)
      .done((resp) => {
        $(".es-message").remove();
        const ok = !!(resp && resp.success);
        const msg = (resp && resp.data && resp.data.message) || (ok ? "资料更新成功" : "资料更新失败");
        displayMessage($form, msg, ok ? "green" : "red");
        alert(msg);

        if (ok) {
          const saved = storage.get("es_attendance_form_data", {});
          const updated = {
            ...saved,
            es_first_name: formData.es_first_name,
            es_last_name: formData.es_last_name,
            es_email: formData.es_email,
            es_fellowship: formData.es_fellowship,
          };
          storage.set("es_attendance_form_data", updated);

          const cc2 = $form.find("select[name=es_phone_country_code]").val();
          const num2 = $form.find("input[name=es_phone_number]").val();
          if (cc2 || num2) syncQuickFormPhone(cc2, num2);

          // 更新成功后显示“修改其他号码”，隐藏“取消”
          ensureProfileActionButtons($form);
          $form.find("#profile-edit-other").show();
          $form.find("#profile-cancel-edit").hide();
        }
      })
      .always(() => {
        setSubmitting($submit, false);
        $alsoDisable.prop('disabled', false).removeAttr('aria-disabled');
      });
  }


  function initTripleForms() {
    if (!$(S.container).length) return;

    // 加载保存的数据到表单
    loadSavedData($(S.quickForm));
    // 表单切换事件
    $(document).on("click", "#switch-to-first-time", function () {
      resetFirstTimeForm();
      switchToForm(S.firstTimeForm);
    });

    $(document).on("click", "#switch-to-profile", function () {
      resetProfileForm();
      prefillPhoneFromStorage($(S.profileForm));
      switchToForm(S.profileForm);
    });

    $(document).on("click", "#back-to-quick, #profile-to-quick", function () {
      switchToForm(S.quickForm);
    });

    $(document).on("click", "#first-to-profile", function () {
      const $firstForm = $(S.firstTimeForm);
      const $profileForm = $(S.profileForm);
      resetProfileForm();
      prefillPhoneFromStorage($profileForm);

      switchToForm(S.profileForm);
    });

    $(document).on("click", "#profile-to-first", function () {
      resetFirstTimeForm();
      switchToForm(S.firstTimeForm);
    });

    // 查找用户资料按钮事件
    $(document).on("click", "#check-profile-phone", function () {
      const $profileForm = $(S.profileForm);
      const cc = $profileForm.find("select[name=es_phone_country_code]").val();
      const num = $profileForm.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      if (!phone) {
        alert("请填写电话号码");
        return;
      }

      checkPhoneAndLoadProfile(phone, $profileForm);
    });

    // 快速签到表单提交
    $(document).on("submit", S.quickForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const cc = $form.find("select[name=es_phone_country_code]").val();
      const num = $form.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      if (!phone) {
        alert("请填写电话号码");
        return;
      }

      const quickData = {
        es_phone_country_code: cc,
        es_phone_number: num
      };
      storage.set("es_attendance_form_data", { ...storage.get("es_attendance_form_data", {}), ...quickData });

      // 一按就显示“提交中/在处理中”
      setSubmitting($submit, true);

      // 让浏览器先绘制状态，再发起请求（避免“晚一拍”）
      requestAnimationFrame(() => {
        api.post("ap_check_phone_exists", { cc: cc, num: num })
          .done((resp) => {
            const exists = !!(resp && resp.success && resp.data && resp.data.exists);
            if (!exists) {
              const go = window.confirm(
                "该电话号码未注册，请完整填写信息进行首次登记。\n\n点击【确定】前往首次登记，点击【取消】留在当前页面。"
              );
              if (go) {
                resetFirstTimeForm();
                switchToForm(S.firstTimeForm);
                setSubmitting($submit, false);
              } else {
                // 停留在当前页面，也要结束 loading
                setSubmitting($submit, false);
                setTimeout(() => {
                  const $numInput = $form.find("input[name=es_phone_number]");
                  $numInput.focus();
                  $numInput.select && $numInput.select();
                }, 0);
              }
              return;
            }

            // 保持 loading，不关；doQuickAttendance 内部会自己 setSubmitting(true/false)
            doQuickAttendance(phone, $form, $submit);
          })
          .fail(() => {
            // 请求失败也要关掉 loading
            setSubmitting($submit, false);
            alert("检查电话号码失败，请稍后再试");
          });
      });

    });

    // 首次登记表单提交
    $(document).on("submit", S.firstTimeForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      const cc = $form.find("select[name=es_phone_country_code]").val();
      const num = $form.find("input[name=es_phone_number]").val();
      const phone = normalizePhone(cc, num);

      // 一按就显示“提交中/在处理中”
      setSubmitting($submit, true);

      requestAnimationFrame(() => {
        api.post("ap_check_phone_exists", { cc: cc, num: num })
          .done((resp) => {
            const exists = !!(resp && resp.success && resp.data && resp.data.exists);

            if (exists) {
              const userChoice = window.confirm(
                "该电话号码已经登记过了。\n" +
                "点击【确定】直接为该号码签到\n" +
                "点击【取消】返回快速签到页面"
              );

              if (userChoice) {
                // 保持 loading；由 doQuickAttendanceFromFirstTime 自行关闭
                doQuickAttendanceFromFirstTime(phone, $form, $submit);
              } else {
                // 切回快速签到，这里要关闭本表单按钮的 loading
                setSubmitting($submit, false);
                const $quickForm = $(S.quickForm);
                $quickForm.find("select[name=es_phone_country_code]").val(cc);
                $quickForm.find("input[name=es_phone_number]").val(num);
                switchToForm(S.quickForm);
                displayMessage($quickForm, "已切换到快速签到页面，电话号码已预填", "green");
              }
            } else {
              // 保持 loading；由 doFullAttendance 自行关闭
              doFullAttendance($form, $submit);
            }
          })
          .fail(() => {
            // 检查失败，走保守路径；由 doFullAttendance 自行关闭
            doFullAttendance($form, $submit);
          });
      });

    });

    // 修改资料表单提交
    $(document).on("submit", S.profileForm, function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();

      const $form = $(this);
      const $submit = getSubmitButton($form);
      if ($submit && $submit.prop('disabled')) return;

      doUpdateProfile($form, $submit);
    });

    // “取消”：回到查找状态
    $(document).on("click", "#profile-cancel-edit", function () {
      const $form = $(S.profileForm);
      resetProfileForm(); // 显示查找区、隐藏资料区并清空资料字段
      // 恢复查找按钮
      const $btn = $("#check-profile-phone");
      $btn.prop("disabled", false).text("查找");
      // 隐藏“修改其他号码”按钮，保留“取消”隐藏（reset 后也不可见）
      $form.find("#profile-edit-other").hide();
    });

    // “修改其他号码”：回到查找状态以便输入新号码
    $(document).on("click", "#profile-edit-other", function () {
      const $form = $(S.profileForm);
      resetProfileForm();
      const $btn = $("#check-profile-phone");
      $btn.prop("disabled", false).text("查找");
      $(this).hide(); // 自己隐藏
    });


    // 清除消息当聚焦到输入框
    $(S.container).on("focus", "input,select", function () {
      $(".es-message").remove();
    });
  }

  /* ========== 原有后台列表（保持不变） ========== */
  function getFilters() {
    return {
      is_member: $("#es_member_filter").val(),
      fellowship: $("#es_fellowship_filter").val(),
      start_date_filter: $("#start_date_filter").val(),
      end_date_filter: $("#end_date_filter").val(),
      last_name: $("#last_name_filter").val(),
      first_name: $("#first_name_filter").val(),
      email: $("#email_filter").val(),
      phone: $("#phone_filter").val(),
      is_new: $("#is_new_filter").is(":checked") ? 'true' : '',
      percentage_filter: $("#percentage_filter").is(":checked"),
    };
  }

  function bindToggleRowEvent() {
    $("tbody").off("click", ".toggle-row").on("click", ".toggle-row", function () {
      $(this).closest("tr").toggleClass("is-expanded");
    });
  }

  function bindPaginationEvent() {
    $(document).off("click", ".pagination-links a").on("click", ".pagination-links a", function (e) {
      e.preventDefault();
      const href = $(this).attr("href") || "";
      const match = href.match(/paged=(\d+)/);
      const page = match ? parseInt(match[1], 10) : 1;
      fetchFilteredResults(page);
    });
  }

  function renderTableHTML(html) {
    $(S.tableBox).html(html);
    bindToggleRowEvent();
    bindPaginationEvent();
  }

  function fetchFilteredResults(page = 1) {
    const params = { ...getFilters(), paged: page };
    showLoader(true);
    api.post("es_filter_attendance", params).done((resp) => {
      if (resp && resp.success) {
        renderTableHTML(resp.data.table_html);
        storage.set("filter_params", params);
      } else {
        alert("加载失败，请稍后再试。");
      }
    }).always(() => showLoader(false));
  }

  function exportCSV() {
    const params = getFilters();
    api.postRaw("es_export_attendance_csv", params).done((response) => {
      const blob = new Blob([response], { type: "text/csv" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      const d = new Date(); const dd = String(d.getDate()).padStart(2, "0"); const mm = String(d.getMonth() + 1).padStart(2, "0"); const yy = d.getFullYear();
      a.href = url; a.download = `Attendance_Report_${dd}-${mm}-${yy}.csv`;
      document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    });
  }

  function bulkMemberUpdate(action) {
    if (!/make_member|make_non_member/.test(action)) return;
    const ids = $('input[name="bulk-select[]"]:checked').map(function () { return this.value; }).get();
    if (!ids.length) return;
    showLoader(true);
    api.post("handle_member_status_update", { ids, member_action: action })
      .done((resp) => {
        const msg = (resp && resp.data && resp.data.message) || "Member status updated.";
        alert(msg);
        const params = storage.get("filter_params") || getFilters();
        fetchFilteredResults(params.paged || 1);
      })
      .fail(() => { alert('更新失败，请稍后再试。'); })
      .always(() => { showLoader(false); });
  }

  function initAdmin() {
    if (!$(S.tableBox).length) return;
    $(document).on("click", "#filter-button", function (e) { e.preventDefault(); fetchFilteredResults(1); });
    $(document).on("click", "#export-csv-button", function (e) { e.preventDefault(); exportCSV(); });
    $(document).on("click", "#doaction, #doaction2", function (e) { e.preventDefault(); const action = $(this).prev("select").val(); bulkMemberUpdate(action); });
    bindToggleRowEvent();
    bindPaginationEvent();
  }

  /* ========== 原有详情弹窗（保持不变） ========== */
  function initModal() {
    const $modal = $(S.modal);
    if (!$modal.length) return;

    const spinnerHTML = '<div class="ap-modal-loading"><div class="ap-spinner"></div></div>';

    function ymd(d) {
      const x = (d instanceof Date) ? d : new Date(d);
      const yyyy = x.getFullYear();
      const mm = String(x.getMonth() + 1).padStart(2, "0");
      const dd = String(x.getDate()).padStart(2, "0");
      return `${yyyy}-${mm}-${dd}`;
    }
    function getDateRangeForModal() {
      const $fe = $(".ap-frontend-dashboard");
      if ($fe.length) {
        const s = $fe.find("#fe_start_date_filter").val();
        const e = $fe.find("#fe_end_date_filter").val();
        return { start: s || ymd(new Date()), end: e || ymd(new Date()) };
      }
      const s = $("#start_date_filter").val();
      const e = $("#end_date_filter").val();
      return { start: s || ymd(new Date()), end: e || ymd(new Date()) };
    }

    let currentXhr = null;
    let lastReqId = 0;

    $(document).on("click", ".view-attendance-button", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const id = $(this).data("attendance-id");
      const dr = getDateRangeForModal();

      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }

      $(S.modalContent).html(spinnerHTML);
      $modal.show();

      const reqId = ++lastReqId;

      currentXhr = $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: {
          action: "get_attendance_info",
          nonce: esAjax.nonce,
          attendance_id: id,
          start_date_filter: dr.start,
          end_date_filter: dr.end
        }
      })
        .done(function (html) {
          if (reqId === lastReqId) {
            $(S.modalContent).html(html);
          }
        })
        .fail(function (xhr, status) {
          if (status === "abort") return;
          $(S.modalContent).html('<div class="ap-modal-error">加载失败，请稍后再试。</div>');
        });
    });

    $(document).on("click", "#attendance-info-modal .close", function () {
      if (currentXhr && currentXhr.readyState !== 4) {
        try { currentXhr.abort(); } catch (_) { }
      }
      $modal.hide();
    });
  }

  /* ========== 启动函数 ========== */
  (function boot() {
    initForm();           // 原有单一表单
    initTripleForms();    // 新增三合一表单
    initAdmin();          // 原有后台功能
    initModal();          // 原有详情弹窗
  })();

  /* ========== 原有前台 dashboard（短代码）（保持不变） ========== */
  (function ($) {
    $(document).on('change', '.ap-frontend-dashboard #fe-check-all', function () {
      const $wrap = $(this).closest('.ap-frontend-dashboard');
      $wrap.find('input.fe-check-item').prop('checked', this.checked);
    });
    $(document).on('change', '.ap-frontend-dashboard input.fe-check-item', function () {
      const $wrap = $(this).closest('.ap-frontend-dashboard');
      const all = $wrap.find('input.fe-check-item').length;
      const selected = $wrap.find('input.fe-check-item:checked').length;
      $wrap.find('#fe-check-all').prop('checked', all > 0 && selected === all);
    });

    function feContainer() {
      const $c = $(".ap-frontend-dashboard");
      return $c.length ? $c : null;
    }
    function feGetFilters($c) {
      return {
        fellowship: $c.find("#fe_fellowship_filter").val(),
        is_member: $c.find("#fe_member_filter").val(),
        last_name: $c.find("#fe_last_name_filter").val(),
        first_name: $c.find("#fe_first_name_filter").val(),
        phone: $c.find("#fe_phone_filter").val(),
        email: $c.find("#fe_email_filter").val(),
        start_date_filter: $c.find("#fe_start_date_filter").val(),
        end_date_filter: $c.find("#fe_end_date_filter").val(),
        is_new: $c.find("#fe_is_new_filter").is(":checked") ? 'true' : '',
        percentage_filter: $c.find("#fe_percentage_filter").is(":checked"),
      };
    }
    function feCurrentPage($c) {
      const $nav = $c.find(".ap-pager");
      const p = parseInt($nav.attr("data-page"), 10);
      return Number.isFinite(p) && p > 0 ? p : 1;
    }
    function feShowLoader($c, show) {
      const $box = $c.find("#loader-box");
      if (!$box.length) return;
      show ? $box.show() : $box.hide();
    }

    function feRenderTable($c, params) {
      feShowLoader($c, true);
      return $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        dataType: "json",
        data: { action: "es_filter_attendance", nonce: esAjax.nonce, fe: 1, ...params },
      })
        .done(function (resp) {
          if (resp && resp.success) {
            $c.find("#table-wrap").html(resp.data.table_html);
            $c.find('#fe-check-all').prop('checked', false);
            $c.off("click.fePage", ".ap-pager a").on("click.fePage", ".ap-pager a", function (e) {
              e.preventDefault();
              const $a = $(this);
              if ($a.attr("aria-disabled") === "true" || $a.hasClass("disabled")) return;
              const nextPage = parseInt($a.data("page"), 10) || 1;
              const paramsNext = { ...feGetFilters($c), paged: nextPage };
              feRenderTable($c, paramsNext);
            });
            requestAnimationFrame(function () { feScrollToContainerTop($c, 10); });
          } else {
            alert("加载失败，请稍后再试。");
          }
        })
        .fail(function () { alert("加载失败，请稍后再试。"); })
        .always(function () { feShowLoader($c, false); });
    }

    function feExportCSV($c) {
      const s = $c.find("#fe_start_date_filter").val();
      const e = $c.find("#fe_end_date_filter").val();

      function dmyLoose(iso) {
        const d = iso ? new Date(iso) : new Date();
        const day = d.getDate();
        const mon = d.getMonth() + 1;
        const yr = d.getFullYear();
        return `${day}/${mon}/${yr}`;
      }
      const titleLine = `Date Range,${dmyLoose(s)} to ${dmyLoose(e)}`;

      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        data: { action: "es_export_attendance_csv", nonce: esAjax.nonce, ...feGetFilters($c) }
      }).done(function (csv) {
        if (csv && csv.charCodeAt && csv.charCodeAt(0) === 0xFEFF) {
          csv = csv.slice(1);
        }
        const finalCsv = "\uFEFF" + titleLine + "\n" + csv;

        const blob = new Blob([finalCsv], { type: "text/csv" });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        const d = new Date(), dd = String(d.getDate()).padStart(2, "0"), mm = String(d.getMonth() + 1).padStart(2, "0"), yy = d.getFullYear();
        a.href = url;
        a.download = `Attendance_Report_${dd}-${mm}-${yy}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      });
    }

    function feBulkAction($c) {
      const action = $c.find("#fe-bulk-action-selector").val();
      if (!/make_member|make_non_member/.test(action)) return;
      const ids = $c.find('input[name="bulk-select[]"]:checked').map(function () { return this.value; }).get();
      if (!ids.length) return;

      const $btn = $c.find("#fe-doaction");
      const oldText = $btn.text();
      $btn.prop("disabled", true).attr("aria-busy", "true").text("Loading…");
      feShowLoader($c, true);

      $.ajax({
        url: esAjax.ajaxurl,
        type: "POST",
        dataType: "json",
        data: { action: "handle_member_status_update", nonce: esAjax.nonce, ids, member_action: action },
      })
        .done(function (resp) {
          alert((resp && resp.data && resp.data.message) || "Member status updated.");
          const params = { ...feGetFilters($c), paged: feCurrentPage($c) };
          feRenderTable($c, params).always(function () {
            $btn.prop("disabled", false).removeAttr("aria-busy").text(oldText);
            feShowLoader($c, false);
          });
        })
        .fail(function () {
          feShowLoader($c, false);
          $btn.prop("disabled", false).removeAttr("aria-busy").text(oldText);
          alert("更新失败，请稍后再试。");
        });
    }

    $(function () {
      const $c = feContainer();
      if (!$c) return;

      $c.on("click", "#fe-filter-button", function (e) {
        e.preventDefault();
        const params = { ...feGetFilters($c), paged: 1 };
        feRenderTable($c, params);
      });

      $c.on("click", "#fe-doaction", function (e) {
        e.preventDefault();
        feBulkAction($c);
      });

      $c.on("click", "#fe-export-csv-button", function (e) {
        e.preventDefault();
        feExportCSV($c);
      });

      $c.off("click.fePage", ".ap-pager a").on("click.fePage", ".ap-pager a", function (e) {
        e.preventDefault();
        const $a = $(this);
        if ($a.attr("aria-disabled") === "true" || $a.hasClass("disabled")) return;
        const nextPage = parseInt($a.data("page"), 10) || 1;
        const paramsNext = { ...feGetFilters($c), paged: nextPage };
        feRenderTable($c, paramsNext);
      });
    });
  })(jQuery);

  /* ========== 原有 First Timers（无刷新刷新 + 导出）（保持不变） ========== */
  (function ($) {
    function apAjaxUrl() {
      const base = esAjax.ajaxurl || '';
      return base + (base.indexOf('?') >= 0 ? '&' : '?') + '_r=' + Date.now();
    }

    function $box() { const $c = $("#ap-first-timers"); return $c.length ? $c : null; }
    function getRange($c) {
      const s = $c.find("#ap-ft-start").val();
      const e = $c.find("#ap-ft-end").val();
      return { start: s || new Date().toISOString().slice(0, 10), end: e || new Date().toISOString().slice(0, 10) };
    }
    function showLoader($c, show) {
      const $l = $c.find("#ap-ft-loader");
      if (!$l.length) return;
      show ? $l.show() : $l.hide();
    }
    function refreshList($c) {
      const rng = getRange($c);
      showLoader($c, true);
      return $.ajax({
        url: apAjaxUrl(),
        type: "POST",
        dataType: "json",
        data: { action: "ap_first_timers_query", nonce: esAjax.nonce, ...rng }
      }).done(function (resp) {
        if (resp && resp.success) {
          $c.find("#ap-ft-list").html(resp.data.html);
          $c.attr('data-count', (resp.data && resp.data.count) ? resp.data.count : 0);
          const t = resp.data.generated_at || '';
          $c.find("#ap-ft-note").text((t ? `数据生成于 ${t}（本站时区）。` : ''));
        } else {
          alert("加载失败，请稍后再试。");
        }
      }).fail(function (xhr) {
        console.error('AJAX fail', xhr && xhr.status, xhr && xhr.responseText);
        alert("加载失败，请稍后再试。");
      }).always(function () {
        showLoader($c, false);
      });
    }
    function exportExcel($c) {
      const cnt = parseInt($c.attr('data-count') || '0', 10);
      if (!Number.isFinite(cnt) || cnt <= 0) {
        alert('所选日期范围内暂无数据，无法导出。');
        return;
      }
      const rng = getRange($c);
      const form = document.createElement("form");
      form.method = "POST";
      form.action = apAjaxUrl();
      form.style.display = "none";

      const add = (k, v) => { const i = document.createElement("input"); i.type = "hidden"; i.name = k; i.value = v; form.appendChild(i); };
      add("action", "ap_first_timers_export");
      add("nonce", esAjax.nonce);
      add("start", rng.start);
      add("end", rng.end);
      add("_r", Date.now().toString());

      document.body.appendChild(form);
      form.submit();
      setTimeout(() => form.remove(), 1000);
    }

    $(function () {
      const $c = $box();
      if (!$c) return;

      $c.on("click", "#ap-ft-refresh", function (e) { e.preventDefault(); refreshList($c); });
      $c.on("click", "#ap-ft-export", function (e) { e.preventDefault(); exportExcel($c); });
      $c.on("change", "#ap-ft-start, #ap-ft-end", function () { refreshList($c); });
    });
  })(jQuery);

});
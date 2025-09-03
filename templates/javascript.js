<script>

$(document).on('pageinit', function() {

	LoadLanguageInitial();	
	//FieldControl();
	//CalendarLayout();
	//DestinationLayout();
	//WeatherLayout();
	//SizeSoundcardFile();
	//$(".infotext").hide();
	var state = false;
	//update_pids();
	getconfig();
});

//***************** End of Functions to be executed during initial loading of page **********************


//**************************** Start Block for Layout control ************************************

// ---------------- Helper für Checkbox-Layouts ----------------
function toggleLayout(checkbox, selector) {
    $(selector).toggle(checkbox.checked);
}

// ---------------- Spezifische Layout-Funktionen ----------------
function CalendarLayout() {
    toggleLayout(document.main_form.cal_det, ".caldet");
}

function DestinationLayout() {
    toggleLayout(document.main_form.dest_det, ".destdet");
}

function WeatherLayout() {
    toggleLayout(document.main_form.weather_det, ".weatherdet");
}

function SizeSoundcardFile()   {
	var filesize = $('#myFile').val();
	if (filesize > 0)   {
		$(".foundusb").show();
	} else {
		$(".foundusb").hide();
	}
}

function SoundDevices()  {
	if ($('#out_list').val() == "012" || $('#out_list').val() == "013")  {
		$('.collapse_usb').show();
	} else {
		$('.collapse_usb').hide();
	}
}


// PLUGIN GET CONFIG

function getconfig() {

	// Ajax request
	$.ajax({ 
		url:  'ajax.cgi',
		type: 'POST',
		data: {
			action: 'getconfig'
		}
	})
	.fail(function( data ) {
		console.log( "getconfig Fail", data );
	})
	.done(function( data ) {
		console.log( "getconfig Success", data );
		$("#main_form").css( 'visibility', 'visible' );
	})
	.always(function( data ) {
		console.log( "getconfig Finished" );
	})

}

/*************************************************************************************************************
/* Funktion : FieldControl --> show/hide some fields depending on selected value
/* @param: 	none
/*
/* @return: nothing
/*************************************************************************************************************/
function FieldControl() {
    console.log("FieldControl");

    const selectedEngine = $('#engine-selector input:checked').val();

    // Alle TTS-Konfigs ein-/ausblenden
    if (selectedEngine) {
        $('.ttsconfig').hide().filter('.' + selectedEngine).show();
        $('.ttsbaseconfig').show();
        $('#t2slang').removeClass('ui-disabled');
        $(".infotext").hide();
    } else {
        $('.ttsconfig, .infotext').hide();
        console.log("No Provider selected");
    }

    // Voice-Input aktivieren/deaktivieren
    const voiceEnabledEngines = ['tts_voicerss', 'tts_elevenlabs', 'tts_piper', 'tts_polly', 'tts_azure'];
    const voiceDisabledEngines = ['tts_respvoice', 'tts_google_cloud'];

    const checkedEngine = Object.keys(document.main_form)
        .find(key => document.main_form[key].checked);

    if (voiceEnabledEngines.includes(checkedEngine)) {
        $('#voice').removeClass('ui-disabled');
    } else if (voiceDisabledEngines.includes(checkedEngine)) {
        $('#voice').addClass('ui-disabled');
    }

    // Refresh select menus
    $('#t2slang, #voice').selectmenu('refresh', true);
}

//************************* End of Block for Layout control ************************************


//************************ Prepare screen during initial load ********************************


/*************************************************************************************************************
/* Funktion : LoadLanguageInitial --> Prepare list of language for all TTS Provider
/* @param: 	none
/*
/* @return: Dropdownbox Language filled
/*************************************************************************************************************/

function LoadLanguageInitial() {
    const t2sValue = $('#val_t2s').val();

    // Keine T2S-Engine ausgewählt
    if (!t2sValue) {
        $('.ttsconfig').hide();
        return;
    }

    // Engine-Auswahl aktualisieren
    $('#engine-selector input[value="' + t2sValue + '"]')
        .prop('checked', true)
        .checkboxradio("refresh");

    // JSON-Stimmen laden für bestimmte Engines
    if (['4001', '5001', '1001'].includes(t2sValue)) {
        GetListOfJsonVoices();
    }

    // Engine-spezifische Initialisierung
    switch (t2sValue) {
        case '3001':
            console.log("Initial: ElevenLabs selected");
            PopulateElevenlabsLang();
            break;
        case '6001':
            console.log("Initial: Responsive Voice selected");
            var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/respvoice.json";
            break;
        case '7001':
            console.log("Initial: Google Cloud selected");
            PopulateGoogleCloudVoices();
            break;
        case '9001':
            console.log("Initial: Azure selected");
            PopulateAzureVoices();
            break;
        default:
            // UI-Elemente ausblenden, wenn keine unterstützte Engine
            $('.field-voice, .colapi, .colsec, .collang, .colmp3, .googleinfo, .azureinfo').hide();
            return;
    }

    // Spracheinstellungen laden (nur wenn URL gesetzt wurde)
    if (typeof url !== 'undefined') {
        $.getJSON(url, function(listlang) {
            listlang.forEach(function(value) {
                const isSelected = value.value === '<TMPL_VAR CODE>';
                const option = `<option ${isSelected ? 'selected="selected"' : ''} value="${value.value}">${value.country}</option>`;
                $('#t2slang').append(option);

                if (isSelected) {
                    $('#t2s_lang_country').val(value.country);
                }
            });
            $('#t2slang').selectmenu('refresh', true);
        });
    }
}

/*************************************************************************************************************
/* Funktion : GetListOfJsonVoices --> Prepare list of language/voices for Polly/Piper/VoiceRSS
/* @param: 	none
/*
/* @return: Dropdownboxes filled
/*************************************************************************************************************/
function GetListOfJsonVoices()    {
	if ($('#val_t2s').val() == '4001')   {
		console.log("Initial: Polly selected");
		var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/polly.json";
		var urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/polly_voices.json";
	} else if ($('#val_t2s').val() == '5001')   {
		console.log("Initial: Piper selected");
		var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/piper.json";
		var urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/piper_voices.json";
	} else if ($('#val_t2s').val() == '1001')   {
		console.log("Initial: Voice RSS selected");
		var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/voicerss.json";
		var urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/voicerss_voices.json";
	}
	$('.field-voice').show();
	$.getJSON(url, function(listlang)  {
		$.each(listlang, function(index, value)  { 
			if (value.value == '<TMPL_VAR CODE>') {
	            $('#t2slang').append('<option selected="selected" value="'+value.value+'">'+value.country+'</option>');
	            $('#t2s_lang_country').val('+value.country+');
	        } else {
	            $('#t2slang').append('<option value="'+value.value+'">'+value.country+'</option>');
	        }
	        $('#t2slang').selectmenu('refresh', true);
        });
        var selectlang = document.getElementById("t2slang");
		var selectedlang = selectlang.options[selectlang.selectedIndex].value;
        $.getJSON(urlvoice, function(listvoice)  {
		    var selectedvoiceList = listvoice.filter(function(item)   {
		    	return item.language === selectedlang;
		  	});
		  	$.each(selectedvoiceList, function(index, value)  {
			    if (value.name == '<TMPL_VAR VOICE>') {
	               	$('#voice').append('<option selected="selected" value="'+value.name+'">'+value.name+'</option>');
	            } else {
	            	$('#voice').append('<option value="'+value.name+'">'+value.name+'</option>');
	            }
				$('#voice').selectmenu('refresh', true);
		    });
		});
	});
	return;
}

	
/*************************************************************************************************************
/* Funktion : prepare voice listing based on previous selected language (VoiceRRS, Polly, Piper)
/* @param: 	none
/*
/* @return: Dropdownbox voice filled
/*************************************************************************************************************/
FilterVoice = function() { 
	if (document.main_form.tts_polly.checked == true || document.main_form.tts_voicerss.checked == true) {
		if (document.main_form.tts_polly.checked == true)    {
			var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/polly_voices.json";
		} else if (document.main_form.tts_voicerss.checked == true)     {
			var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/voicerss_voices.json";
		}
  		var select = document.getElementById("t2slang");
		var selectedlanguage = select.options[select.selectedIndex].value;
	    $('#voice').empty();
	    $.getJSON(url, function(listvoice)  {
			$('#voice').append('<option selected="true" value="" disabled><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');
		    var selectedvoiceList = listvoice.filter(function(item){
		    	return item.language === selectedlanguage;
		  	});
			console.log(selectedvoiceList);
		    $.each(selectedvoiceList, function(index, value) {
				console.log(value);
			    if (value.name == '<TMPL_VAR VOICE>') {
	               	$('#voice').append('<option selected="selected" value="'+value.name+'">'+value.name+'</option>');
	            } else {
	            	$('#voice').append('<option value="'+value.name+'">'+value.name+'</option>');
	            }
	            $('#voice').selectmenu('refresh', true);
		    });
		});
	    return selectedlanguage;
	    $('#voice').selectmenu('refresh', true);  
 	} else if (document.main_form.tts_google_cloud.checked == true ) {
		console.log("FilterVoice Google");
		$('#voice').empty();
		//populateVoice();
	} else if (document.main_form.tts_piper.checked == true) {
        var selectedlanguage = $("#t2slang option:selected").val();
        if (selectedlanguage) {
            $('#voice').empty();
            var url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/piper_voices.json";
            $.getJSON(url, function(listvoice) {
                $('#voice').append('<option selected="true" value="" disabled><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');
                var selectedvoiceList = listvoice.filter(function(item) {
                    return item.language === selectedlanguage;
                });
                $.each(selectedvoiceList, function(index, value) {
                    if (value.name == '<TMPL_VAR VOICE>') {
                        $('#voice').append('<option selected="selected" value="' + value.name + '">' + value.name + '</option>');
                    } else {
                        $('#voice').append('<option value="' + value.name + '">' + value.name + '</option>');
                    }
                    $('#voice').selectmenu('refresh', true);
                });
            });
			$('#apikey').val("");
        }
    } else {
		//$('#voice').empty();    	
	}
	var isocode = '<TMPL_VAR CODE>'.substr(0, 2);
	$('#langiso').val(isocode);
}



// Je nach Engine funktion aufrufen
function ButtonLoadVoices() {
	if (document.main_form.tts_google_cloud.checked == true) {
		PopulateGoogleCloudVoices();
	} else if (document.main_form.tts_azure.checked == true) {
		PopulateAzureVoices();
	} else if (document.main_form.tts_elevenlabs.checked == true) {
		PopulateElevenlabsLang();
	}
}

// Gemeinsame Funktion für Fehleranzeige
function showError(error, type = '') {
    console.log(`${type}${error}`);
    $(".infotext").show();
    $("#info").css("color", "red").text(error);
    resetLanguageVoiceDropdowns();
}

// Dropdowns zurücksetzen
function resetLanguageVoiceDropdowns() {
    $('#t2slang, #voice').empty();
    $('.collang').hide();
    $('#t2slang, #voice').selectmenu('refresh', true);
}

// Spezialisierte Wrapper
const ApiError = (error) => showError(error, 'Error Code: ');
const AccessError = (error) => showError(error, 'Access Error: ');

function LanguageVoiceReset()    {
	$('#t2slang').empty();
	$('#voice').empty();
}

// Update Pids from index.cgi
function update_pids() {
	
	$.post( 'index.cgi', {
	ajax: 'getpids' })

	.done(function(resp) {
		console.log( "ajax_post", "success", resp );
		if(resp.pids.mqtt_handler != null) {
			$("#handlerstate").attr("style", "color:green").html("MQTT Handler running (PID"+resp.pids.mqtt_handler+")");
		} else {
			$("#handlerstate").attr("style", "color:red").html("MQTT Handler not running");
		}
		//if(resp.pids.mqtt_watchdog != null) {
		//	$("watchdogstate").attr("style", "color:green").html("Watchdog running (PID"+resp.pids.mqtt_watchdog+")");
		//} else {
		//	$("watchdogstate").attr("style", "color:red").html("Watchdog not running");
		//}
		
	})
	.fail(function(resp) {
		$("#handlerstate").attr("style", "color:red").html("Failed to query PID");
		console.log( "ajax_post", "error", resp );
	})
	.always(function(resp) {
		//console.log( "ajax_post", "finished", resp );
	});
}

/*************************************************************************************************************
/* Funktion : PopulateGoogleCloudVoices --> Populates voices from Google Cloud into Dropdown
/* @param: 	none
/*
/* @return: Dropdown filled
/*************************************************************************************************************/	  

function PopulateGoogleCloudVoices() {
    if ($('#apikey').val()) {
		var apidata = document.getElementById('apikey').value;
        const url = "https://texttospeech.googleapis.com/v1/voices?key=" + $('#apikey').val();
        var getCountryNames = new Intl.DisplayNames([navigator.language || navigator.userLanguage],    {
            type: 'region'
        });
		
		let apikey = document.getElementById('apikey').value.trim();
		console.log("Actual API-Key: " +  apikey);
        fetch(url).then(function(response) {
            if (response.status != 200) {
				if (apidata.length != 39)  {
					error = '<TMPL_VAR ERRORS.ERR_APIKEY39>';
					ApiError(error);
					return;
				} else {
					error = '<TMPL_VAR T2S.VOICE_ERROR1>: ' + response.status + '. <TMPL_VAR T2S.VOICE_ERROR2>: ' + $('#apikey').val() + ' <TMPL_VAR T2S.VOICE_ERROR3>';
					AccessError(error);
					return;
				}
            }
            return response.json();
            })
			.then(function(data) {
            if (!data) {
                return;
            }
            const langs = new Map();
            const voices = new Map();
            const savedLanguageCode = '<TMPL_VAR CODE>';
            data.voices.forEach(element => {
                if (element.languageCodes.length > 0 && element.languageCodes[0].length == 5) {
                    lanIsoCode = element.languageCodes[0];
                    countryIsoCode = lanIsoCode.substring(3);
                    countryname = getCountryNames.of(countryIsoCode);
                    langs.set(lanIsoCode, countryname);
                    voices.set(element.name, lanIsoCode);
                }
            });
            langs.forEach(function(langname, langcode) {
                $('#t2slang').append('<option value="' + langcode + '">' + langname + '</option>');
            });
            if (savedLanguageCode) {
                console.log("Selecting Langugage Code");
                $('#t2slang').val(savedLanguageCode);
            } else {
                $('#t2slang').prop('selectedIndex', 0);
            }
            voices.forEach(function(locale, voicename) {
                $('#voice').append('<option value="' + voicename + '" data-lang="' + locale + '">' + voicename + '</option>');
            });
            //populateVoice();
 			state = false;
			$('.collang').show();
            $('#t2slang').selectmenu('refresh', true);
            $('#voice').selectmenu('refresh', true);
        });
    }
}


/*************************************************************************************************************
/* Funktion : PopulateAzureVoices --> Populates voices from Azure into Dropdown
/* @param: 	none
/*
/* @return: Dropdown filled
/*************************************************************************************************************/	  

function PopulateAzureVoices() {
    console.log("populateAzureVoices");
    var regionOptions = document.getElementById("regionOptions");

	var apikey = document.getElementById('apikey').value;

    if (!$('#apikey').val()) return;
	console.log("Actual API-Key: " +  apikey);

    $('#t2slang').empty();
    $('#voice').empty();
    $("#info").empty();

    var request = new XMLHttpRequest();
    request.open(
        'GET',
        'https://' + regionOptions.value + ".tts.speech." +
        (regionOptions.value.startsWith("china") ? "azure.cn" : "microsoft.com") +
        "/cognitiveservices/voices/list",
        true
    );
    request.setRequestHeader("Ocp-Apim-Subscription-Key", $('#apikey').val());

    request.onload = function () {
        if (request.status >= 200 && request.status < 400) {
            const data = JSON.parse(this.response);

            // --- Eindeutige Sprachen sammeln ---
            let langMap = {};
            data.forEach(v => {
                if (!langMap[v.Locale]) {
                    langMap[v.Locale] = v.LocaleName; // Mapping Code → Name
                }
            });

            // --- Language Dropdown ---
            $('#t2slang').append('<option selected disabled value=""><TMPL_VAR T2S.SELECT_LANGUAGE_DROPDOWN></option>');
            Object.keys(langMap).forEach(language => {
                $('#t2slang').append('<option value="' + language + '">' + langMap[language] + '</option>');
            });

            // --- Voice Dropdown Dummy ---
            $('#voice').append('<option selected disabled value=""><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');

            $('#t2slang').selectmenu('refresh', true);
            $('#voice').selectmenu('refresh', true);

            // --- Funktion: Stimmen für Language laden ---
            function loadVoices(selectedLang) {
                $('#voice').empty();
                $('#voice').append('<option selected disabled value=""><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');

                const voices = data.filter(v => v.Locale === selectedLang);
                voices.forEach(voice => {
					if (voice.ShortName == '<TMPL_VAR VOICE>') {
						$('#voice').append('<option selected="selected" value="' + voice.ShortName + '">' + voice.DisplayName + " (" + voice.Gender + ")" + '</option>');
					} else {
						$('#voice').append('<option value="' + voice.ShortName + '">' + voice.DisplayName + " (" + voice.Gender + ")" + '</option>');
					}
                });
                $('#voice').selectmenu('refresh', true);
            }

            // --- OnChange Handler ---
            $('#t2slang').off('change').on('change', function () {
                const selectedLang = $(this).val();
				$('#t2slang').val(selectedLang).selectmenu('refresh', true);
                loadVoices(selectedLang);
            });

            // --- Initial: vorausgewählte Sprache ---
            const defaultLang = '<TMPL_VAR CODE>';
            if (defaultLang && langMap[defaultLang]) {
                $('#t2slang').val(defaultLang).selectmenu('refresh', true);
                loadVoices(defaultLang);
            }

        } else {
            if (apikey.length != 32 && apikey.length != 36 && apikey.length != 84) {
                error = '<TMPL_VAR ERRORS.ERR_AZURE>';
                ApiError(error);
                return;
            } else {
                error = '<TMPL_VAR T2S.VOICE_ERROR1>: ' + this.status + '. <TMPL_VAR T2S.VOICE_ERROR2>: ' + apikey + ' <TMPL_VAR T2S.VOICE_ERROR3>';
                AccessError(error);
                return;
            }
        }
        $('.collang').show();
    };

    request.send();
    state = false;
}


/*************************************************************************************************************
/* Funktion : PopulateElevenlabsLang --> Populates language from ElevenLabs into Dropdown
/* @param: 	none
/*
/* @return: Dropdown filled
/*************************************************************************************************************/	  
async function PopulateElevenlabsLang() {
    console.log("populateElevenlabsLang");

    // 🔹 Helper nur lokal für ElevenLabs
    function refreshSelectMenu($select) {
        if ($select.hasClass("ui-selectmenu")) {
            $select.selectmenu("refresh", true);
        } else {
            try { $select.selectmenu("destroy"); } catch (e) {}
            $select.selectmenu();
        }
    }

    let apikey = document.getElementById('apikey').value.trim();
	console.log("Actual API-Key: " +  apikey);
    if (!document.main_form.tts_elevenlabs.checked) return;

    const headers = { "xi-api-key": apikey };
    const langSelect = $('#t2slang');
    const voiceSelect = $('#voice');

    function handleError(msg) {
        ApiError(msg);
        $(".infotext").show();
    }

    try {
        // === Sprachen abrufen ===
        langSelect.empty();
        const modelResp = await fetch("https://api.elevenlabs.io/v1/models", { headers });
        if (!modelResp.ok) {
            if (apikey.length !== 32 && apikey.length !== 51) {
                return handleError('<TMPL_VAR ERRORS.ERR_ELEVEN>');
            }
            return AccessError(`<TMPL_VAR T2S.VOICE_ERROR1>: ${modelResp.status} - Unauthorized! API-Key: ${apikey} <TMPL_VAR T2S.VOICE_ERROR3>`);
        }

        const models = await modelResp.json();
        if (!models?.[0]?.languages) {
            return handleError("<TMPL_VAR ERRORS.ERROR_ELEVEN1>");
        }

        $('.collang').show();
        $('#info').empty();
        $(".infotext").hide();
		
        langSelect.append('<option selected disabled><TMPL_VAR T2S.SELECT_LANGUAGE_DROPDOWN></option>');
        models[0].languages.forEach(lang => {
            const selected = (lang.language_id === "<TMPL_VAR TTS.messageLang>") ? "selected" : "";
            langSelect.append(`<option ${selected} value="${lang.language_id}">&nbsp;${lang.name}</option>`);
        });

        refreshSelectMenu(langSelect);

        // === Stimmen abrufen ===
        voiceSelect.empty();
        const voiceResp = await fetch("https://api.elevenlabs.io/v2/voices", { headers });
        if (!voiceResp.ok) {
            return handleError(`Voices Request failed: ${voiceResp.status}`);
        }

        const voices = await voiceResp.json();
        if (!voices?.voices?.length) {
            return handleError("<TMPL_VAR ERRORS.ERROR_ELEVEN2>");
        }

        window.elevenVoices = voices.voices;
        PopulateVoiceDropdown(window.elevenVoices);

        langSelect.on('change', function () {
            const selectedLang = $(this).val();
            let filtered = window.elevenVoices.filter(v => v.labels?.language_id === selectedLang);
            if (!filtered.length) {
                //console.warn("Keine Stimmen für Sprache", selectedLang, "- fallback auf alle Stimmen");
                filtered = window.elevenVoices;
            }
            PopulateVoiceDropdown(filtered);
        });

        function PopulateVoiceDropdown(voices) {
            console.log("PopulateVoiceDropdown");
            voiceSelect.empty();
            voiceSelect.append('<option selected disabled><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');
            voices.forEach(voice => {
                const selected = (voice.voice_id === "<TMPL_VAR TTS.voice>") ? "selected" : "";
                voiceSelect.append(`
                    <option ${selected} id="${voice.preview_url}" value="${voice.voice_id}">
                        &nbsp;${voice.name} - ${voice.labels?.age || "?"}, ${voice.labels?.description || ""}
                    </option>`);
            });
            refreshSelectMenu(voiceSelect);
        }

    } catch (err) {
        console.error("Error populating ElevenLabs data:", err);
        handleError("<TMPL_VAR ERRORS.ERROR_ELEVEN3>");
    }
}


/****************** All Click/Change/Submit Functions ******************/

/******** Engine Selection ********/
$("input[name='t2s_engine']").on('click', function () {
    console.log('Dropdownboxes filled');
    LanguageVoiceReset();
    $('#info').empty();

    url = null;

    if ($(this).is(':checked') && $(this).val() == '1001') {
        url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/voicerss.json";
		urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/voicerss_voices.json";
    } else if ($(this).is(':checked') && $(this).val() == '4001') {
        url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/polly.json";
		urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/polly_voices.json";
    } else if ($(this).is(':checked') && $(this).val() == '5001') {
        url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/piper.json";
		urlvoice = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/piper_voices.json";
        $('.infotext').hide();
    } else if ($(this).is(':checked') && $(this).val() == '6001') {
        url = "/plugins/<TMPL_VAR PLUGINDIR>/voice_engines/langfiles/respvoice.json";
        $('.infotext').hide();
    } else if ($(this).is(':checked') && $(this).val() == '3001') {
        PopulateElevenlabsLang();
    } else if ($(this).is(':checked') && $(this).val() == '9001') {
        PopulateAzureVoices();
    } else if ($(this).is(':checked') && $(this).val() == '7001') {
        PopulateElevenlabsLang();
    } else {
        return;
    }
	// === Nur JSON basierte Engines werden geladen
    $('#t2slang').prop('selectedIndex', 0);
    if (url) {
        $.getJSON(url, function (listlang) {
            $('#t2slang').append('<option selected="true" value="" disabled><TMPL_VAR T2S.SELECT_LANGUAGE_DROPDOWN></option>');
            $('#voice').append('<option selected="true" value="" disabled><TMPL_VAR T2S.SELECT_VOICE_DROPDOWN></option>');
            $.each(listlang, function (index, value) {
                if (value.value == '<TMPL_VAR CODE>') {
                    $('#t2slang').append('<option selected="selected" value="' + value.value + '">' + value.country + '</option>');
                    $('#t2s_lang_country').val('+value.country+');
                } else {
                    $('#t2slang').append('<option value="' + value.value + '">' + value.country + '</option>');
                }
            });
            $('select').selectmenu('refresh', true);
        });
    }
});


/******** Generate and play Test MP3 ********/
$("#testbox_submit").click(function(){
	var t2sengine = $("input[name=t2s_engine]:checked").val();
	var language = document.getElementById("t2slang").value;
	var voice = document.getElementById("voice").value;
	var apikey = document.getElementById("apikey").value;
	var secretkey = document.getElementById("seckey").value;
	var testfile = 1;
	var nocache = 1;
	var speaktext = $("#testtext").val();
	const hash = md5(speaktext);
	
	console.log('File: ' + hash + '.mp3');
	$.get("/plugins/<TMPL_VAR LBPPLUGINDIR>/index.php",
		{ nocache: nocache,
		  filename: hash,
		  text: speaktext,
		  t2sengine: t2sengine,
		  language: language,
		  voice: voice,
		  apikey: apikey,
		  testfile: testfile,
		  secretkey: secretkey
	})
	.done(function(data){
		let beat = new Audio("<TMPL_VAR HTTPINTERFACE>/" + hash + ".mp3?rnd=" + Date.now());
		beat.play();
	})
	.fail(function(dataobj, textStatus){
		console.log("Fehler: " + textStatus);
	});
});


/******* Copy keys based on selectedengine ********/
$('#engine-selector input[name="t2s_engine"]').change(async function() {
	const t2sengine = $(this).val();
	if (!t2sengine) return;

	// Feedback während des Ladens
	$("#apikey, #seckey").attr("placeholder", "Lade...").val('');

	try {
		const result = await $.ajax({
			url: "./index.cgi",
			type: 'GET',
			dataType: 'json',
			data: { getkeys: 1, t2s_engine: t2sengine }
		});

		if (result && result.apikey !== undefined && result.seckey !== undefined) {
			$("#apikey").val(result.apikey);
			$("#seckey").val(result.seckey);
		} else {
			console.warn("Keine oder unvollständige Keys zurückgegeben:", result);
			$("#apikey, #seckey").val('');
		}
	} catch (err) {
		console.error("Fehler beim Laden der Keys:", err);
		$("#apikey, #seckey").val('');
	} finally {
		$("#apikey, #seckey").attr("placeholder", "");
	}

	// --- Nach Key-Update Dropdowns refreshen ---
	const apikey = $("#apikey").val().trim();

	// Polly
	if (t2sengine === 'polly') {
	if (typeof PopulatePollyVoices === "function") PopulatePollyVoices(apikey);
	}
	// Google Cloud
	else if (t2sengine === 'google_cloud') {
		if (typeof PopulateGoogleCloudVoices === "function") PopulateGoogleCloudVoices(apikey);
	}
	// Azure
	else if (t2sengine === 'azure') {
		if (typeof PopulateAzureVoices === "function") PopulateAzureVoices(apikey);
	}
	// ElevenLabs
	else if (t2sengine === 'elevenlabs') {
		if (typeof PopulateElevenlabsLang === "function") PopulateElevenlabsLang();
	}
	// VoiceRSS
	else if (t2sengine === 'voicerss') {
		if (typeof PopulateVoiceRSSVoices === "function") PopulateVoiceRSSVoices(apikey);
	}
});

	// Initial Keys laden, falls schon eine Engine ausgewählt ist
	const initialEngine = $('#engine-selector input[name="t2s_engine"]:checked').val();
	if (initialEngine) {
	$('#engine-selector input[name="t2s_engine"]:checked').trigger('change');
}


function markInputValid(id){ 
    $("#"+id).removeClass("input-invalid").addClass("input-valid"); 
}
function markInputInvalid(id){ 
    $("#"+id).removeClass("input-valid").addClass("input-invalid"); 
}



/*************************************************************************************************************
/* Funktion : validateTTSForm --> execute various checks during submit
/* @param: 	e, state (passed from ElevenLabs, Google Cloud and Azure voice functions)
/*
/* @return: Error or submit (save)
/*************************************************************************************************************/	  
async function validateTTSForm(e, state) {
    const apidata     = $("#apikey").val()?.trim() || "";
    const seckey      = $("#seckey").val()?.trim() || "";
    const selectlang  = $("#t2slang").val();
    const selectvoice = $("#voice").val();
    const form        = document.main_form;
    const safeState   = state ?? false;

    function showError(msg, focusEl) {
        console.log("showError:", msg);
        $(".infotext").show();
        $("#info").css("color", "red").text(msg);
        $("html, body").animate({ scrollTop: 0 });
        if (focusEl) $(focusEl).focus();
        if (e) e.preventDefault();
        return false;
    }

    function markInputValid(id){ $("#"+id).removeClass("input-invalid").addClass("input-valid"); }
    function markInputInvalid(id){ $("#"+id).removeClass("input-valid").addClass("input-invalid"); }

    // ---------------- Helper API-Checks ----------------
    async function validateElevenlabs(apikey){
        try { 
            const resp = await fetch("https://api.elevenlabs.io/v1/models", { headers: { "xi-api-key": apikey } }); 
            return resp.ok; 
        } catch (err) { 
            console.error("validateElevenlabs error:", err);
            return false; 
        }
    }

    async function validateVoiceRSS(apikey){
        try { 
            const resp = await fetch(`https://api.voicerss.org/?key=${apikey}&hl=en-us&src=test`); 
            const text = await resp.text(); 
            return !text.toUpperCase().includes("ERROR"); 
        } catch (err) { 
            console.error("validateVoiceRSS error:", err);
            return false; 
        }
    }

    async function validateGoogleCloud(apikey){
        try { 
            const resp = await fetch(`https://texttospeech.googleapis.com/v1/voices?key=${apikey}`); 
            return resp.ok; 
        } catch (err) { 
            console.error("validateGoogleCloud error:", err);
            return false; 
        }
    }

    async function validateAzure(apikey, region){
        try { 
            const resp = await fetch(`https://${region}.tts.speech.microsoft.com/cognitiveservices/voices/list`, { headers: { "Ocp-Apim-Subscription-Key": apikey } }); 
            return resp.ok; 
        } catch (err) { 
            console.error("validateAzure error:", err);
            return false; 
        }
    }

    // ---------------- Form-Checks ----------------
    try {
        const engineChecks = [
            { field: form.tts_polly,          keyLen: 20, secLen: 40, validate: null,              checkVoice: true },
            { field: form.tts_elevenlabs,     keyLen: [32, 51],       validate: validateElevenlabs, checkVoice: true },
            { field: form.tts_voicerss,       keyLen: 32,             validate: validateVoiceRSS,   checkVoice: true },
            { field: form.tts_google_cloud,   keyLen: 39,             validate: validateGoogleCloud,checkVoice: true },
            { field: form.tts_azure,          keyLen: null,           validate: (key)=>validateAzure(key, $("#regionOptions").val()), checkVoice: true },
            { field: form.tts_piper,          keyLen: null,           validate: null,               checkVoice: true },
            { field: form.tts_respvoice,      keyLen: null,           validate: null,               checkVoice: false, langOnly: true } // nur Language
        ];

        for (const engine of engineChecks) {
            if (engine.field && engine.field.checked) {
                console.log("Validation for Engine:", engine.field.id);

                // --- API-Key check ---
                if (engine.keyLen) {
                    const validKey = Array.isArray(engine.keyLen) 
                        ? engine.keyLen.includes(apidata.length) 
                        : apidata.length === engine.keyLen;

                    if (!validKey) { 
                        markInputInvalid("apikey"); 
                        return showError('<TMPL_VAR ERRORS.ERR_APIKEY>', '#apikey'); 
                    } else { 
                        markInputValid("apikey"); 
                        console.log("API-Key length OK");
                    }
                }

                // --- Secret-Key check ---
                if (engine.secLen) {
                    if (seckey.length !== engine.secLen) { 
                        markInputInvalid("seckey"); 
                        return showError('<TMPL_VAR ERRORS.ERR_SECRETKEY>', '#seckey'); 
                    } else { 
                        markInputValid("seckey"); 
                        console.log("Secret-Key length OK");
                    }
                }

                // --- API validation (falls vorhanden) ---
                if (engine.validate) {
                    const ok = await engine.validate(apidata);
                    if (!ok) { 
                        markInputInvalid("apikey"); 
                        return showError(`❌ <TMPL_VAR ERRORS.ERROR_VAL_KEYS1>`, '#apikey'); 
                    } else { 
                        markInputValid("apikey"); 
                        console.log("API-Key validation OK");
                    }
                }

                // --- Language check (nach erfolgreichen Keys) ---
                if (!selectlang) {
                    return showError('<TMPL_VAR T2S.VALIDATE_T2S_LANG>', '#t2slang');
                }

                // --- Voice check (falls aktiviert und nach Keys) ---
                if (engine.checkVoice && !selectvoice) {
                    return showError('<TMPL_VAR T2S.VALIDATE_VOICE>', '#voice');
                }

                console.log("All Checks for Engine passed");
                return true; // ✅ alles OK
            }
        }

        console.log("No engine selected");
        return false; // ❌ falls keine Engine ausgewählt
    } catch (err) {
        console.error("validateTTSForm Exception:", err);
        return showError('<TMPL_VAR ERRORS.ERROR_API>', '#apikey');
    }
}




//************************ Prepare Document ready ********************************/

$(document).ready(function() {
    SoundDevices();
    $('#engine-selector input').change(FieldControl);

    // Set initial language ISO
    var isocode = '<TMPL_VAR CODE>'.substr(0, 2);
    $('#langiso').val(isocode);

    
	function showError(msg, $element, e) {
        console.log("showError:", msg);
        $(".infotext").show();
        $("#info").css("color", "red").text(msg);
        $("html, body").animate({ scrollTop: 0 });
        if ($element) $($element).focus();
        if (e) e.preventDefault();
        return false;
    }

    function validateJingle() {
		var $fileGong = $("#file_gong");
		if ($fileGong.length === 0) return true; // Element nicht vorhanden, Skip
		if (!$fileGong.val() || $fileGong.val().trim() === '') {
			showError('<TMPL_VAR T2S.VALIDATE_JINGLE>', $fileGong);
			return false;
		}
		return true;
	}

	function validateOutputDevice() {
		var $outList = $("#out_list");
		if ($outList.length === 0) return true; // Element fehlt
		if (!$outList.val() || $outList.val().trim() === '') {
			showError('<TMPL_VAR T2S.VALIDATE_OUTPUT>', $outList);
			return false;
		}
		return true;
	}

	function validateStoragePath() {
		var $storage = $("#STORAGEPATH");
		if ($storage.length === 0) return true; // Element fehlt
		if (!$storage.val() || $storage.val().trim() === '') {
			showError('<TMPL_VAR T2S.VALIDATE_PATH>', $storage);
			return false;
		}
		return true;
	}

  $("form#main_form").on("submit", async function(e) {
        e.preventDefault(); // abbremsen

        $('#info').empty();
        $(".infotext").hide();
		
		setInterval(function(){ update_pids(); }, 5000);
		update_pids();

        // --- Asynchroner API-Key & TTS Check ---
		const asyncValid = await validateTTSForm(e);

		if (!asyncValid) {
			console.log("Asynchrone TTS-Validierung fehlgeschlagen");
			return; // Formular NICHT absenden
		}

        // --- Synchrone Checks danach ---
        let syncValid = true;
			if (!validateJingle()) syncValid = false;
			if (!validateOutputDevice()) syncValid = false;
			if (!validateStoragePath()) syncValid = false;

        if (!syncValid) {
            console.log("Synchron Validation failed");
            return; // Formular NICHT absenden
        }

        // --- Alles OK → Formular absenden ---
        console.log("All checks passed, form will be send...");
        this.submit();
    });
});


</script>
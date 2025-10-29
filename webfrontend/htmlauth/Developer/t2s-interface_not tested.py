import json
import time
import uuid
import paho.mqtt.client as mqtt # just an example

P2W_Text = "Text to be spoken";
psubfolder = "folder where your plugin is installed (according to your plugin.cfg)";

def t2svoice(P2W_Text, psubfolder, mqtt_creds):
    RESP_TIMEOUT = 12  # seconds

    # ---------- Generate Unique Topics ----------
    client = psubfolder
    corr = str(uuid.uuid4())
    req_topic = f"tts-publish/{client}/{corr}"
    resp_topic = f"tts-subscribe/{client}/{corr}"

    # ---------- Prepare Text Payload ----------
    clean_text = (P2W_Text or "").replace('\n', '').replace('\r', '')
    if clean_text == "":
        # add your Fallback if no text

    payload = json.dumps({
        "text": clean_text,
        "nocache": 0,
        "logging": 1,
        "mp3files": 0,
        "client": client,
        "corr": corr,
        "reply_to": resp_topic
    })

    # ---------- MQTT Setup ----------
    host = mqtt_creds.get("brokerhost", "127.0.0.1")
    port = mqtt_creds.get("brokerport", 1883)
    user = mqtt_creds.get("brokeruser", "")
    password = mqtt_creds.get("brokerpass", "")

    reply = {}

    def on_message(client, userdata, msg):
        try:
            data = json.loads(msg.payload.decode())
            response = data.get("response", data)
            if response.get("corr") == corr or response.get("original", {}).get("corr") == corr:
                reply["file"] = response.get("file")
                reply["httpinterface"] = response.get("interfaces", {}).get("httpinterface") or response.get("httpinterface")
        except Exception:
            pass  # Ignore malformed messages

    try:
        # ---------- MQTT Connection Setup ----------
		# insert code to retrieve MQTT connection details (eg. from general.json)
        if user or password:
            client_mqtt.username_pw_set(user, password)
        client_mqtt.on_message = on_message
        client_mqtt.connect(host, port, keepalive=60)
        client_mqtt.loop_start()
        client_mqtt.subscribe("tts-subscribe/#")
        client_mqtt.publish(req_topic, payload)

        # ---------- Wait for Response ----------
        end_time = time.time() + RESP_TIMEOUT
        while not reply and time.time() < end_time:
            time.sleep(0.1)

        client_mqtt.loop_stop()
        client_mqtt.disconnect()

        # ---------- Handle Response or Fallback ----------
        if reply.get("file") and reply.get("httpinterface"):
            url = f"{reply['httpinterface']}/{reply['file']}"
            global full_path_to_mp3
            full_path_to_mp3 = url
            # enter your code for further proccessing
        else:
            # enter your code for fallback

const mqtt = require('mqtt');
const { v4: uuidv4 } = require('uuid');

const P2W_Text = "Text to be spoken";
const psubfolder = "folder where your plugin is installed (according to your plugin.cfg)";

async function t2svoice(P2W_Text, psubfolder, mqttCreds) {
  const RESP_TIMEOUT = 12000; // milliseconds

  // ---------- Generate Unique Topics ----------
  const client = psubfolder;
  const corr = uuidv4();
  const reqTopic = `tts-publish/${client}/${corr}`;
  const respTopic = `tts-subscribe/${client}/${corr}`;

  // ---------- Prepare Text Payload ----------
  const cleanText = (P2W_Text || '').replace(/\r?\n|\r/g, '');
  if (cleanText === '') {
    return usepico(); // Fallback if text is empty
  }

  const payload = JSON.stringify({
    text: cleanText,
    nocache: 0,
    logging: 1,
    mp3files: 0,
    client,
    corr,
    reply_to: respTopic,
  });

  // ---------- MQTT Connection Setup ----------
  // insert code to retrieve MQTT connection details (eg. from general.json)
  const {
    brokerhost = '127.0.0.1',
    brokerport = 1883,
    brokeruser = '',
    brokerpass = '',
  } = mqttCreds;

  const mqttOptions = {
    host: brokerhost,
    port: brokerport,
    username: brokeruser || undefined,
    password: brokerpass || undefined,
    protocol: 'mqtt',
  };

  return new Promise((resolve) => {
    const clientMQTT = mqtt.connect(mqttOptions);

    let reply = null;
    const timeout = setTimeout(() => {
      clientMQTT.end();
      resolve(usepico()); // Fallback on timeout
    }, RESP_TIMEOUT);

    clientMQTT.on('connect', () => {
      clientMQTT.subscribe('tts-subscribe/#', () => {
        clientMQTT.publish(reqTopic, payload);
      });
    });

    clientMQTT.on('message', (topic, message) => {
      try {
        const data = JSON.parse(message.toString());
        const response = data.response || data;
        const matchCorr =
          response.corr === corr || response.original?.corr === corr;

        if (matchCorr) {
          reply = {
            file: response.file,
            httpinterface:
              response.interfaces?.httpinterface || response.httpinterface,
          };

          if (reply.file && reply.httpinterface) {
            clearTimeout(timeout);
            clientMQTT.end();
            const url = `${reply.httpinterface}/${reply.file}`;
            global.full_path_to_mp3 = url;
            // enter your code for further proccessing
          }
        }
      } catch {
        // Ignore malformed messages
      }
    });

    clientMQTT.on('error', () => {
      clearTimeout(timeout);
      clientMQTT.end();
      // enter your code for fallback
    });
  });
}
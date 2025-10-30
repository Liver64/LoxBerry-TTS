P2W_Text = "Text to be spoken";
psubfolder = "folder where your plugin is installed (according to your plugin.cfg)";

import { connectAsync, IClientOptions, IClientPublishOptions } from 'mqtt';
import { v4 as uuidv4 } from 'uuid';

interface TTSResponse {
  file?: string;
  httpinterface?: string;
  corr?: string;
}

export async function t2svoice(
  P2W_Text: string,
  psubfolder: string,
  mqttCreds: {
    brokerhost?: string;
    brokerport?: number;
    brokeruser?: string;
    brokerpass?: string;
  }
): Promise<string> {
  const RESP_TIMEOUT = 12000; // Timeout in milliseconds

  // ---------- Generate Unique Topics ----------
  const client = psubfolder;
  const corr = uuidv4() || Date.now().toString();
  const req_topic = `tts-publish/${client}/${corr}`;
  const resp_topic = `tts-subscribe/${client}/${corr}`;

  // ---------- Prepare Text Payload ----------
  const cleanText = (P2W_Text || '').replace(/\r?\n|\r/g, '');
  if (cleanText === '') {
    return usepico(); // Fallback if no text
  }

  // ---------- Create JSON Payload ----------
  const payload = JSON.stringify({
    text: cleanText,		// Text to converted into voice mp3 file
    nocache: 0,				// 0 = check if Voice file already created and ship from cache 1 =  force TTS to recreate MP3 file
    logging: 1,				// 0 = no TTS logs were passed. 1 = you get all logs from TTS for further proccessing
    mp3files: 0,			// 0 =   1 = 
    client,					// identifyer or requester (plugin installation folder)
    corr,					// unique identifyer for this specific pub request
    reply_to: resp_topic,	// fallback if corr doesn't work
  });

  // ---------- MQTT Connection Setup ----------
  // insert code to retrieve MQTT connection details (eg. from general.json)
  const {
    brokerhost = '127.0.0.1',
    brokerport = 1883,
    brokeruser = '',
    brokerpass = '',
  } = mqttCreds;

  const mqttOptions: IClientOptions = {
    username: brokeruser || undefined,
    password: brokerpass || undefined,
    reconnectPeriod: 0,
  };

  try {
    const mqttClient = await connectAsync(`mqtt://${brokerhost}:${brokerport}`, mqttOptions);

    let reply: TTSResponse | null = null;

    // Subscribe to response topic
    await mqttClient.subscribeAsync('tts-subscribe/#');

    mqttClient.on('message', (topic, message) => {
      try {
        const data = JSON.parse(message.toString());
        const response = data.response ?? data;
        const parsed: TTSResponse = {
          file: response.file,
          httpinterface: response.interfaces?.httpinterface ?? response.httpinterface,
          corr: response.corr ?? response.original?.corr,
        };
        if (parsed.corr === corr) {
          reply = parsed;
        }
      } catch {
        // Ignore malformed messages
      }
    });

    // Publish request
    const publishOptions: IClientPublishOptions = { qos: 0 };
    mqttClient.publish(req_topic, payload, publishOptions);

    // Wait for response with timeout
    const start = Date.now();
    while (!reply && Date.now() - start < RESP_TIMEOUT) {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }

    await mqttClient.endAsync();

    // ---------- Handle Response or Fallback ----------
    if (reply?.file && reply.httpinterface) {
      const url = `${reply.httpinterface}/${reply.file}`;
		// enter your code for further proccessing
    } else {
		// enter your code for fallback
    }
  } catch {
    // Fallback code if MQTT connection fails
  }
}
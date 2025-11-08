my $P2W_Text = "Text to be spoken";
my $psubfolder = "folder where your plugin is installed (according to your plugin.cfg)";

sub t2svoice {
    use strict;
    use warnings;
    use LoxBerry::System;
    use LoxBerry::IO;
    use JSON qw(encode_json decode_json);
    use Net::MQTT::Simple;
    use Time::HiRes qw(gettimeofday);

    our ($P2W_Text, $lbplogdir, $logfile, $psubfolder);
    my $RESP_TIMEOUT = 12;

    # ---------- Generate Topics ----------
    my $client = $psubfolder;
    my $corr = eval { chomp(my $u = `uuidgen`); $u || time } || time;
    my $req_topic  = "tts-publish/$client/$corr";
    my $resp_topic = "tts-subscribe/$client/$corr";

    # ---------- Create JSON Payload ----------
    $P2W_Text //= '';
    $P2W_Text =~ s/\R//g;
    if ($P2W_Text eq '') {
        return usepico();
    }

    my $payload_json = encode_json({
        text     => $P2W_Text,
		function => "",
        nocache  => 0,
        logging  => 1,
        mp3files => 0,
        client   => $client,
        corr     => "$corr",
        reply_to => $resp_topic,
    });

    # ---------- Parse Response ----------
    my $parse_response = sub {
        my ($msg) = @_;
        my $d = eval { decode_json($msg) };
        return undef unless $d;
        my $r = $d->{response} // $d;
        return {
            file          => $r->{file},
            httpinterface => $r->{interfaces}->{httpinterface} // $r->{httpinterface},
            corr          => $r->{corr} // $r->{original}->{corr},
        };
    };

    # ---------- Use Local MQTT ----------
    my ($host, $port, $user, $pass) = do {
        my $cred = LoxBerry::IO::mqtt_connectiondetails();
        (
            $cred->{brokerhost} // '127.0.0.1',
            $cred->{brokerport} // 1883,
            $cred->{brokeruser} // '',
            $cred->{brokerpass} // ''
        )
    };

    $ENV{MQTT_SIMPLE_ALLOW_INSECURE_LOGIN} = 1;
    my $mqtt;
    eval {
        $mqtt = Net::MQTT::Simple->new("$host:$port");
        $mqtt->login($user, $pass) if $user || $pass;
        1;
    };
    if ($mqtt) {
        my ($reply);
        $mqtt->subscribe("tts-subscribe/#" => sub {
            my ($t, $m) = @_;
            my $parsed = $parse_response->($m);
            return unless $parsed;
            if ($parsed->{corr} && $parsed->{corr} eq $corr) {
                $reply = $parsed;
            }
        });

        $mqtt->publish($req_topic, $payload_json);

        my $end = time + $RESP_TIMEOUT;
        while (!$reply && time < $end) {
            $mqtt->tick();
            select undef, undef, undef, 0.1;
        }
        $mqtt->disconnect();

        if ($reply && $reply->{file} && $reply->{httpinterface}) {
            my $url = "$reply->{httpinterface}/$reply->{file}";
            our $full_path_to_mp3 = $url;
            # enter your code for further proccessing
        }
    }

    # ---------- Fallback ----------
    # enter your code for fallback
}
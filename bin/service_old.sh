#!/bin/bash


#Soundkarten anzeigen WebUI
if [ $1 == "sc_show" ];then
cat /proc/asound/cards >/tmp/soundcards.txt
sed -n '{p;n}' /tmp/soundcards.txt >/tmp/soundcards2.txt
fi

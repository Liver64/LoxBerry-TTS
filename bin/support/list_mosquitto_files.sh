#!/bin/bash
find /etc/mosquitto -printf '%M %u %g %m %p\n' 2>/dev/null | sort

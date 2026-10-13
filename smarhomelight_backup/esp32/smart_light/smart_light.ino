#include <Wire.h>
#include <Adafruit_PWMServoDriver.h>

Adafruit_PWMServoDriver pwm = Adafruit_PWMServoDriver();

#define SERVOMIN  150   // ~0 degrees
#define SERVOMAX  600   // ~180 degrees
#define SERVO_FREQ 50   // 50Hz para sa analog servos

void setup() {
  Serial.begin(115200);
  Serial.println("3 Servos Sweep Test");

  Wire.begin(21, 22);      // SDA=GPIO21, SCL=GPIO22
  pwm.begin();
  pwm.setPWMFreq(SERVO_FREQ);
  delay(10);

  // Center tanan servo sa startup
  setAllServos(90);
  delay(500);
}

void loop() {
  Serial.println("Sweep 0 -> 180");
  for (int angle = 0; angle <= 180; angle += 2) {
    setAllServos(angle);
    delay(20);
  }

  delay(500);

  Serial.println("Sweep 180 -> 0");
  for (int angle = 180; angle >= 0; angle -= 2) {
    setAllServos(angle);
    delay(20);
  }

  delay(500);
}

void setAllServos(int angle) {
  int pulse = map(angle, 0, 180, SERVOMIN, SERVOMAX);
  pwm.setPWM(0, 0, pulse);   // Servo 1
  pwm.setPWM(1, 0, pulse);   // Servo 2
  pwm.setPWM(2, 0, pulse);   // Servo 3
}
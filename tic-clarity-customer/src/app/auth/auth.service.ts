import { HttpClient } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { environment } from 'src/environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AuthService {

  private _user;
  COOKIE_NAME = "CLARITY-AUTH-TOKEN";

  constructor(private http: HttpClient) { 
  }

  canActivate(): boolean {
    return true;
  }

  isLoggedIn() {
    //return true;
    return this.getCookie(this.COOKIE_NAME) !== null;
  }

  
  login(OTP) {

    let authenticationPromise;

    //If not logged in, we have to 
    if(!this.isLoggedIn()) {      
      authenticationPromise = this.http.get(environment.authentication_server + "/authenticate/" + OTP).subscribe(
        {
          next: (response) => {this.setCookie(this.COOKIE_NAME, (response as any).token);},
          error: () => {window.location.href = "https://login.ticsin.com";}
        });
        
    } else {
      authenticationPromise = new Promise <any>((resolve, reject) => {
        //we should refresh the token here
        setTimeout( () => {
          resolve(null);
        }, 1500);
      });
    }

    return authenticationPromise;
  }

  logout() {
    this.deleteCookie(this.COOKIE_NAME);
    window.location.href = "https://login.ticsin.com";
  }

  //We should decode the value here
  public set user(value) {

    const tokens = value.split(":");
    this._user = JSON.parse(atob(tokens[0]));
  }

  public get user() {
    return this._user;
  }

  public setCookie(name: string, val: string) {
    const date = new Date();
    const value = val;

    // Set it expire in 7 days
    date.setTime(date.getTime() + (7 * 24 * 60 * 60 * 1000));

    // Set it
    document.cookie = name + "=" + value + "; expires=" + date.toUTCString() + "; path=/";
  }

  public getCookie(name: string) {
    const value = "; " + document.cookie;
    const parts = value.split("; " + name + "=");

    if (parts.length == 2) {
      return parts.pop().split(";").shift();
    }
    return null;
  }

  get groupID() {
    return "abc";
  }

  private deleteCookie(name: string) {
    const date = new Date();

    // Set it expire in -1 days
    date.setTime(date.getTime() + (-1 * 24 * 60 * 60 * 1000));

    // Set it
    document.cookie = name + "=; expires=" + date.toUTCString() + "; path=/";
  }
}
